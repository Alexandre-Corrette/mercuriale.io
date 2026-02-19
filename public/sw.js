/**
 * Mercuriale.io — Service Worker
 * Version : 1.5.0
 *
 * STRATÉGIES DE CACHE :
 * - App Shell (CSS, JS, icônes) : Cache First
 * - Pages HTML : Network First avec fallback offline.html
 * - API /bons-livraison (list) : Network First avec fallback cache
 * - API /bons-livraison/{id}/image : Cache First (immutable after validation)
 * - API referentiels : Network First avec fallback cache
 * - Autres API : Network Only
 *
 * SÉCURITÉ :
 * - Versioning strict du cache (CACHE_VERSION)
 * - Nettoyage des anciens caches à l'activation
 * - Aucune interception des requêtes d'authentification
 * - Kill switch : si /api/sw/status retourne {active: false}, le SW se désenregistre
 */

var CACHE_VERSION = 'mercuriale-v1.6.0';
var APP_SHELL_CACHE = CACHE_VERSION + '-shell';

// Fichiers de l'App Shell à pré-cacher
// Note : le projet utilise AssetMapper (les CSS/JS sont servis dynamiquement)
// On pré-cache uniquement les fichiers statiques connus
var APP_SHELL_FILES = [
    '/offline.html',
    '/css/offline.css',
    '/css/login.css',
    '/manifest.json',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/css/admin.css',
    '/css/push-notification.css',
    '/css/bl-consultation.css',
    '/js/offline-retry.js',
    '/css/install-prompt.css',
    '/css/extraction.css'
];

// ── INSTALL ──
self.addEventListener('install', function (event) {
    console.info('[SW] Installation version', CACHE_VERSION);
    event.waitUntil(
        caches.open(APP_SHELL_CACHE)
            .then(function (cache) {
                console.info('[SW] Pré-cache App Shell');
                return cache.addAll(APP_SHELL_FILES);
            })
            .then(function () {
                // Force l'activation immédiate (pas d'attente)
                return self.skipWaiting();
            })
    );
});

// ── ACTIVATE ──
self.addEventListener('activate', function (event) {
    console.info('[SW] Activation version', CACHE_VERSION);
    event.waitUntil(
        caches.keys()
            .then(function (cacheNames) {
                return Promise.all(
                    cacheNames
                        .filter(function (name) {
                            // Supprime tous les caches qui ne correspondent pas à la version actuelle
                            return name.startsWith('mercuriale-') && name !== APP_SHELL_CACHE;
                        })
                        .map(function (name) {
                            console.info('[SW] Suppression ancien cache :', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(function () {
                // Prend le contrôle de tous les onglets immédiatement
                return self.clients.claim();
            })
    );
});

// ── FETCH ──
self.addEventListener('fetch', function (event) {
    var request = event.request;
    var url = new URL(request.url);

    // SÉCURITÉ : ne jamais intercepter les requêtes vers d'autres domaines
    if (url.origin !== self.location.origin) {
        return;
    }

    // SÉCURITÉ : ne jamais cacher les requêtes POST/PUT/DELETE
    if (request.method !== 'GET') {
        return;
    }

    // SÉCURITÉ : ne jamais cacher les endpoints d'authentification
    if (url.pathname.startsWith('/login') || url.pathname.startsWith('/logout') || url.pathname.startsWith('/api/login')) {
        return;
    }

    // API Référentiels : Network First avec fallback cache
    if (url.pathname === '/api/referentiels/offline') {
        event.respondWith(
            fetch(request)
                .then(function (response) {
                    // Mettre en cache la réponse
                    if (response.ok) {
                        var responseClone = response.clone();
                        caches.open(APP_SHELL_CACHE).then(function (cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(function () {
                    // Fallback sur le cache
                    return caches.match(request);
                })
        );
        return;
    }

    // API BL images : Cache First (images are immutable after validation)
    if (url.pathname.match(/^\/api\/bons-livraison\/\d+\/image$/)) {
        event.respondWith(
            caches.match(request)
                .then(function (cached) {
                    return cached || fetch(request).then(function (response) {
                        if (response.ok) {
                            var responseClone = response.clone();
                            caches.open(APP_SHELL_CACHE).then(function (cache) {
                                cache.put(request, responseClone);
                            });
                        }
                        return response;
                    });
                })
        );
        return;
    }

    // API BL list : Network First avec fallback cache
    if (url.pathname === '/api/bons-livraison') {
        event.respondWith(
            fetch(request)
                .then(function (response) {
                    if (response.ok) {
                        var responseClone = response.clone();
                        caches.open(APP_SHELL_CACHE).then(function (cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(function () {
                    return caches.match(request);
                })
        );
        return;
    }

    // Autres requêtes API : Network Only
    if (url.pathname.startsWith('/api/')) {
        return;
    }

    // App Shell (assets statiques) : Cache First
    if (isAppShellRequest(url.pathname)) {
        event.respondWith(
            caches.match(request)
                .then(function (cached) {
                    return cached || fetch(request).then(function (response) {
                        // Ne cacher que les réponses valides
                        if (response.ok) {
                            var responseClone = response.clone();
                            caches.open(APP_SHELL_CACHE).then(function (cache) {
                                cache.put(request, responseClone);
                            });
                        }
                        return response;
                    });
                })
        );
        return;
    }

    // Pages HTML : Network First avec fallback offline
    if (request.headers.get('Accept') && request.headers.get('Accept').includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then(function (response) {
                    // Cacher la page pour usage offline futur
                    if (response.ok) {
                        var responseClone = response.clone();
                        caches.open(APP_SHELL_CACHE).then(function (cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(function () {
                    // Hors ligne : tenter le cache, sinon page offline
                    return caches.match(request).then(function (cached) {
                        return cached || caches.match('/offline.html');
                    });
                })
        );
        return;
    }
});

// ── PUSH NOTIFICATIONS ──
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

    var url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(function (clientList) {
                // Focus existing tab if one is open on the same origin
                for (var i = 0; i < clientList.length; i++) {
                    var client = clientList[i];
                    if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                        client.focus();
                        client.navigate(url);
                        return;
                    }
                }
                // No existing tab — open new window
                return self.clients.openWindow(url);
            })
    );
});

// ── HELPERS ──
function isAppShellRequest(pathname) {
    var extensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.ico', '.woff', '.woff2'];
    return extensions.some(function (ext) {
        return pathname.endsWith(ext);
    });
}

// ── BACKGROUND SYNC ──
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

// ── MESSAGE HANDLER ──
self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'REQUEST_SYNC') {
        console.info('[SW] Sync demandée par le client');
        if (self.registration.sync) {
            self.registration.sync.register('sync-pending-bls').catch(function (err) {
                console.warn('[SW] Impossible d\'enregistrer la sync:', err);
            });
        }
    }

    // Kill switch (sera activé au Sprint 6 via /api/sw/status)
    // if (event.data && event.data.type === 'KILL_SW') {
    //     self.registration.unregister().then(function () {
    //         console.warn('[SW] Service Worker désinstallé par kill switch');
    //     });
    // }
});
