/**
 * Mercuriale.io — Service Worker Registration
 *
 * SÉCURITÉ :
 * - Le SW est enregistré uniquement en HTTPS ou localhost
 * - Aucun fallback silencieux : les erreurs sont loguées
 * - Détection du mode d'affichage (standalone vs navigateur)
 */
(function () {
    'use strict';

    // Détection du mode PWA installée
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    if (isStandalone) {
        document.documentElement.classList.add('pwa-standalone');
        console.info('[PWA] Mode standalone détecté');
    }

    // Enregistrement du Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' })
                .then(function (registration) {
                    console.info('[PWA] Service Worker enregistré, scope :', registration.scope);

                    // Vérification des mises à jour
                    registration.addEventListener('updatefound', function () {
                        var newWorker = registration.installing;
                        console.info('[PWA] Nouveau Service Worker détecté, installation...');

                        newWorker.addEventListener('statechange', function () {
                            if (newWorker.state === 'activated') {
                                // Notification à l'utilisateur qu'une mise à jour est disponible
                                if (navigator.serviceWorker.controller) {
                                    console.info('[PWA] Mise à jour installée. Rechargez pour en bénéficier.');
                                    // TODO Sprint 5 : afficher un toast UI "Mise à jour disponible"
                                }
                            }
                        });
                    });
                })
                .catch(function (error) {
                    console.error('[PWA] Échec enregistrement Service Worker :', error);
                });

            // Détection du changement online/offline
            window.addEventListener('online', function () {
                document.documentElement.classList.remove('pwa-offline');
                document.documentElement.classList.add('pwa-online');
                console.info('[PWA] Connexion rétablie');
                // TODO Sprint 2 : déclencher la synchronisation
            });

            window.addEventListener('offline', function () {
                document.documentElement.classList.remove('pwa-online');
                document.documentElement.classList.add('pwa-offline');
                console.warn('[PWA] Connexion perdue — mode offline');
            });

            // État initial
            if (navigator.onLine) {
                document.documentElement.classList.add('pwa-online');
            } else {
                document.documentElement.classList.add('pwa-offline');
            }
        });
    } else {
        console.warn('[PWA] Service Worker non supporté par ce navigateur');
    }
})();
