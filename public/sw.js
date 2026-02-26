/**
 * Mercuriale.io — Service Worker
 * Version : 2.0.0
 *
 * STRATÉGIES DE CACHE :
 * ─────────────────────
 * CSS / JS         → Stale While Revalidate (sert le cache, met à jour en arrière-plan)
 * Images / Fonts   → Cache First (assets immutables ou rarement modifiés)
 * Pages HTML       → Network First avec fallback offline.html
 * API BL images    → Cache First (immutable après validation)
 * API BL list      → Network First avec fallback cache
 * API référentiels → Network First avec fallback cache
 * Autres API       → Network Only
 *
 * SÉCURITÉ :
 * ──────────
 * - Versioning strict du cache (CACHE_VERSION)
 * - Nettoyage automatique des anciens caches à l'activation
 * - Aucune interception des requêtes d'authentification
 * - Requêtes cross-origin ignorées
 * - Seules les requêtes GET sont interceptées
 * - Kill switch prévu via /api/sw/status
 *
 * CACHE-BUSTING :
 * ───────────────
 * - Incrémenter CACHE_VERSION à chaque déploiement
 * - Le precache de l'App Shell est re-téléchargé à chaque nouvelle version
 * - Les CSS/JS sont mis à jour en arrière-plan (StaleWhileRevalidate)
 *   → 1re visite après déploiement : ancien CSS servi, nouveau téléchargé
 *   → 2e visite : nouveau CSS servi
 *   → Pour un rafraîchissement immédiat, utiliser asset() avec hash dans Twig
 */

// ══════════════════════════════════════════════════════════════
// CONFIGURATION
// ══════════════════════════════════════════════════════════════

var CACHE_VERSION = 'mercuriale-v2.0.0';
var APP_SHELL_CACHE = CACHE_VERSION + '-shell';
var RUNTIME_CACHE = CACHE_VERSION + '-runtime';

/**
 * Fichiers de l'App Shell à pré-cacher lors de l'installation.
 * Ces fichiers sont re-téléchargés à chaque changement de CACHE_VERSION.
 */
var APP_SHELL_FILES = [
    '/offline.html',
    '/manifest.json',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/css/tokens.css',
    '/css/components/button.css',
    '/css/components/card.css',
    '/css/offline.css',
    '/css/login.css',
    '/css/admin.css',
    '/css/push-notification.css',
    '/css/bl-consultation.css',
    '/css/install-prompt.css',
    '/css/extraction.css',
    '/js/offline-retry.js'
];

/**
 * Endpoints d'authentification — jamais interceptés ni cachés.
 */
var AUTH_PATHS = ['/login', '/logout', '/api/login', '/api/token/refresh'];

/**
 * Timeout réseau pour les stratégies Network First (en ms).
 * Si le réseau ne répond pas dans ce délai, on sert le cache.
 */
var NETWORK_TIMEOUT_MS = 3000;

// ══════════════════════════════════════════════════════════════
// INSTALL
// ══════════════════════════════════════════════════════════════

self.addEventListener('install', function (event) {
    console.info('[SW] Installation version', CACHE_VERSION);

    event.waitUntil(
        caches.open(APP_SHELL_CACHE)
            .then(function (cache) {
                console.info('[SW] Pré-cache App Shell (' + APP_SHELL_FILES.length + ' fichiers)');
                return cache.addAll(APP_SHELL_FILES);
            })
            .then(function () {
                // Activation immédiate sans attendre la fermeture des onglets
                return self.skipWaiting();
            })
    );
});

// ══════════════════════════════════════════════════════════════
// ACTIVATE
// ══════════════════════════════════════════════════════════════

self.addEventListener('activate', function (event) {
    console.info('[SW] Activation version', CACHE_VERSION);

    event.waitUntil(
        caches.keys()
            .then(function (cacheNames) {
                return Promise.all(
                    cacheNames
                        .filter(function (name) {
                            // Supprimer tous les caches Mercuriale qui ne sont pas de la version courante
                            return name.startsWith('mercuriale-') &&
                                   name !== APP_SHELL_CACHE &&
                                   name !== RUNTIME_CACHE;
                        })
                        .map(function (name) {
                            console.info('[SW] Suppression ancien cache :', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(function () {
                // Prendre le contrôle de tous les onglets immédiatement
                return self.clients.claim();
            })
            .then(function () {
                // Notifier les clients qu'une nouvelle version est active
                return self.clients.matchAll({ type: 'window' });
            })
            .then(function (clients) {
                clients.forEach(function (client) {
                    client.postMessage({ type: 'SW_UPDATED', version: CACHE_VERSION });
                });
            })
    );
});

// ══════════════════════════════════════════════════════════════
// FETCH — Routage des requêtes
// ══════════════════════════════════════════════════════════════

self.addEventListener('fetch', function (event) {
    var request = event.request;
    var url = new URL(request.url);
    var pathname = url.pathname;

    // ── Filtres de sécurité ──

    // Ignorer les requêtes cross-origin
    if (url.origin !== self.location.origin) {
        return;
    }

    // Ignorer les requêtes non-GET (POST, PUT, DELETE, PATCH)
    if (request.method !== 'GET') {
        return;
    }

    // Ignorer les endpoints d'authentification
    if (isAuthRequest(pathname)) {
        return;
    }

    // ── Routage par type de requête ──

    // 1. API BL images : Cache First (immutable après validation)
    if (pathname.match(/^\/api\/bons-livraison\/\d+\/image$/)) {
        event.respondWith(cacheFirst(request, RUNTIME_CACHE));
        return;
    }

    // 2. API BL list : Network First avec fallback cache
    if (pathname === '/api/bons-livraison') {
        event.respondWith(networkFirst(request, RUNTIME_CACHE));
        return;
    }

    // 3. API référentiels offline : Network First avec fallback cache
    if (pathname === '/api/referentiels/offline') {
        event.respondWith(networkFirst(request, RUNTIME_CACHE));
        return;
    }

    // 4. Autres requêtes API : Network Only (pas d'interception)
    if (pathname.startsWith('/api/')) {
        return;
    }

    // 5. CSS / JS : Stale While Revalidate
    if (isCssOrJs(pathname)) {
        event.respondWith(staleWhileRevalidate(request, APP_SHELL_CACHE));
        return;
    }

    // 6. Images, fonts, icônes : Cache First
    if (isStaticAsset(pathname)) {
        event.respondWith(cacheFirst(request, APP_SHELL_CACHE));
        return;
    }

    // 7. Pages HTML : Network First avec fallback offline.html
    if (isHtmlRequest(request)) {
        event.respondWith(networkFirstWithOfflineFallback(request));
        return;
    }
});

// ══════════════════════════════════════════════════════════════
// STRATÉGIES DE CACHE
// ══════════════════════════════════════════════════════════════

/**
 * Stale While Revalidate :
 * Sert la réponse cachée immédiatement (si disponible),
 * puis met à jour le cache en arrière-plan avec la réponse réseau.
 *
 * Avantage : performance instantanée + mise à jour automatique.
 * Inconvénient : l'utilisateur voit l'ancienne version au 1er chargement
 *                après un déploiement. La nouvelle version est servie au 2e.
 */
function staleWhileRevalidate(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request).then(function (cachedResponse) {
            var fetchPromise = fetch(request)
                .then(function (networkResponse) {
                    if (networkResponse.ok) {
                        cache.put(request, networkResponse.clone());
                    }
                    return networkResponse;
                })
                .catch(function (err) {
                    console.warn('[SW] Fetch échoué (SWR):', request.url, err);
                    return cachedResponse;
                });

            // Retourner le cache immédiatement si disponible, sinon attendre le réseau
            return cachedResponse || fetchPromise;
        });
    });
}

/**
 * Cache First :
 * Sert la réponse cachée si disponible, sinon va chercher sur le réseau
 * et met en cache pour la prochaine fois.
 *
 * Utilisé pour les assets immutables (images, fonts, icônes).
 */
function cacheFirst(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request).then(function (cachedResponse) {
            if (cachedResponse) {
                return cachedResponse;
            }

            return fetch(request).then(function (networkResponse) {
                if (networkResponse.ok) {
                    cache.put(request, networkResponse.clone());
                }
                return networkResponse;
            });
        });
    });
}

/**
 * Network First :
 * Essaie le réseau en priorité. Si le réseau échoue ou timeout,
 * sert la réponse cachée.
 *
 * Utilisé pour les données API qui changent fréquemment.
 */
function networkFirst(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return promiseTimeout(NETWORK_TIMEOUT_MS, fetch(request))
            .then(function (networkResponse) {
                if (networkResponse.ok) {
                    cache.put(request, networkResponse.clone());
                }
                return networkResponse;
            })
            .catch(function () {
                return cache.match(request).then(function (cachedResponse) {
                    if (cachedResponse) {
                        console.info('[SW] Fallback cache pour :', request.url);
                        return cachedResponse;
                    }
                    // Pas de cache disponible
                    return new Response('{"error":"offline","message":"Données non disponibles hors ligne"}', {
                        status: 503,
                        headers: { 'Content-Type': 'application/json' }
                    });
                });
            });
    });
}

/**
 * Network First avec fallback offline.html :
 * Comme Network First, mais pour les pages HTML :
 * si ni le réseau ni le cache ne répondent, on affiche la page offline.
 */
function networkFirstWithOfflineFallback(request) {
    return caches.open(APP_SHELL_CACHE).then(function (cache) {
        return fetch(request)
            .then(function (networkResponse) {
                if (networkResponse.ok) {
                    cache.put(request, networkResponse.clone());
                }
                return networkResponse;
            })
            .catch(function () {
                return cache.match(request).then(function (cachedResponse) {
                    return cachedResponse || caches.match('/offline.html');
                });
            });
    });
}

// ══════════════════════════════════════════════════════════════
// PUSH NOTIFICATIONS
// ══════════════════════════════════════════════════════════════

self.addEventListener('push', function (event) {
    if (!event.data) {
        return;
    }

    var payload;
    try {
        payload = event.data.json();
    } catch (e) {
        console.warn('[SW] Push payload invalide:', e);
        return;
    }

    var title = payload.title || 'Mercuriale';
    var options = {
        body: payload.body || '',
        icon: payload.icon || '/icons/icon-192x192.png',
        badge: payload.badge || '/icons/icon-192x192.png',
        tag: payload.tag || 'mercuriale-default',
        data: {
            url: payload.url || '/'
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var targetUrl = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(function (clientList) {
                // Focus un onglet existant si possible
                for (var i = 0; i < clientList.length; i++) {
                    var client = clientList[i];
                    if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                        client.focus();
                        client.navigate(targetUrl);
                        return;
                    }
                }
                // Aucun onglet ouvert — en ouvrir un nouveau
                return self.clients.openWindow(targetUrl);
            })
    );
});

// ══════════════════════════════════════════════════════════════
// BACKGROUND SYNC
// ══════════════════════════════════════════════════════════════

self.addEventListener('sync', function (event) {
    if (event.tag === 'sync-pending-bls') {
        console.info('[SW] Background Sync déclenché : sync-pending-bls');
        event.waitUntil(
            self.clients.matchAll({ type: 'window', includeUncontrolled: false })
                .then(function (clients) {
                    clients.forEach(function (client) {
                        client.postMessage({ type: 'SYNC_TRIGGERED' });
                    });
                })
        );
    }
});

// ══════════════════════════════════════════════════════════════
// MESSAGE HANDLER
// ══════════════════════════════════════════════════════════════

self.addEventListener('message', function (event) {
    if (!event.data || !event.data.type) {
        return;
    }

    switch (event.data.type) {
        case 'REQUEST_SYNC':
            console.info('[SW] Sync demandée par le client');
            if (self.registration.sync) {
                self.registration.sync.register('sync-pending-bls').catch(function (err) {
                    console.warn('[SW] Impossible d\'enregistrer la sync:', err);
                });
            }
            break;

        case 'SKIP_WAITING':
            // Permet au client de forcer l'activation d'un nouveau SW
            self.skipWaiting();
            break;

        case 'GET_VERSION':
            // Permet au client de connaître la version du SW actif
            if (event.source) {
                event.source.postMessage({ type: 'SW_VERSION', version: CACHE_VERSION });
            }
            break;

        // Kill switch (à activer via /api/sw/status)
        // case 'KILL_SW':
        //     self.registration.unregister().then(function () {
        //         console.warn('[SW] Service Worker désinstallé par kill switch');
        //     });
        //     break;
    }
});

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════

/**
 * Vérifie si la requête concerne un endpoint d'authentification.
 */
function isAuthRequest(pathname) {
    return AUTH_PATHS.some(function (authPath) {
        return pathname.startsWith(authPath);
    });
}

/**
 * Vérifie si la requête concerne un fichier CSS ou JS.
 */
function isCssOrJs(pathname) {
    return pathname.endsWith('.css') || pathname.endsWith('.js');
}

/**
 * Vérifie si la requête concerne un asset statique (hors CSS/JS).
 * Images, fonts, icônes, manifest.
 */
function isStaticAsset(pathname) {
    var extensions = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.eot'];
    return extensions.some(function (ext) {
        return pathname.endsWith(ext);
    });
}

/**
 * Vérifie si la requête concerne une page HTML.
 */
function isHtmlRequest(request) {
    var accept = request.headers.get('Accept');
    return accept && accept.includes('text/html');
}

/**
 * Promise avec timeout.
 * Rejette la promise si elle ne se résout pas dans le délai donné.
 */
function promiseTimeout(ms, promise) {
    return new Promise(function (resolve, reject) {
        var timer = setTimeout(function () {
            reject(new Error('Timeout après ' + ms + 'ms'));
        }, ms);

        promise
            .then(function (response) {
                clearTimeout(timer);
                resolve(response);
            })
            .catch(function (err) {
                clearTimeout(timer);
                reject(err);
            });
    });
}