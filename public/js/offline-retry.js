/**
 * Offline page — retry button + pending BL count from raw IndexedDB.
 * This file runs WITHOUT importmap (offline page can't load ES modules).
 * No Dexie dependency — uses raw IndexedDB API.
 */
(function () {
    'use strict';

    // Retry button
    var retryBtn = document.getElementById('retry-btn');
    if (retryBtn) {
        retryBtn.addEventListener('click', function () {
            window.location.reload();
        });
    }

    // Read pending BL count from IndexedDB (raw API, no Dexie)
    var pendingEl = document.getElementById('pending-count');
    if (!pendingEl) return;

    try {
        var request = indexedDB.open('mercurialeDB');

        request.onsuccess = function (event) {
            var db = event.target.result;

            if (!db.objectStoreNames.contains('pendingBL')) {
                pendingEl.textContent = '0';
                db.close();
                return;
            }

            var tx = db.transaction('pendingBL', 'readonly');
            var store = tx.objectStore('pendingBL');
            var countRequest = store.count();

            countRequest.onsuccess = function () {
                pendingEl.textContent = String(countRequest.result);
            };

            countRequest.onerror = function () {
                pendingEl.textContent = '0';
            };

            tx.oncomplete = function () {
                db.close();
            };
        };

        request.onerror = function () {
            pendingEl.textContent = '0';
        };
    } catch (e) {
        pendingEl.textContent = '0';
    }
})();
