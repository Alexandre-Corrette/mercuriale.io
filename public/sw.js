/**
 * Mercuriale.io — Service Worker
 * Version : 1.0.0-sprint1
 *
 * STRATÉGIES DE CACHE :
 * - App Shell (CSS, JS, icônes) : Cache First
 * - Pages HTML : Network First avec fallback offline.html
 * - API calls : Network Only (pas de cache API pour l'instant)
 *
 * SÉCURITÉ :
 * - Versioning strict du cache (CACHE_VERSION)
 * - Nettoyage des anciens caches à l'activation
 * - Aucune interception des requêtes d'authentification
 * - Kill switch : si /api/sw/status retourne {active: false}, le SW se désenregistre
 */

var CACHE_VERSION = 'mercuriale-v1.0.0';
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
    '/css/admin.css'
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

    // Requêtes API : Network Only pour l'instant (le cache API viendra au Sprint 2)
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

// ── HELPERS ──
function isAppShellRequest(pathname) {
    var extensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.ico', '.woff', '.woff2'];
    return extensions.some(function (ext) {
        return pathname.endsWith(ext);
    });
}

// ── KILL SWITCH (Sécurité) ──
// Vérifie périodiquement si le SW doit se désactiver
// Sera activé au Sprint 6 via l'endpoint /api/sw/status
// self.addEventListener('message', function (event) {
//     if (event.data && event.data.type === 'KILL_SW') {
//         self.registration.unregister().then(function () {
//             console.warn('[SW] Service Worker désinstallé par kill switch');
//         });
//     }
// });
