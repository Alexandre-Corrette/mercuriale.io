import { Controller } from '@hotwired/stimulus';
import { countPendingBLs } from '../js/db.js';
import { syncAll } from '../js/syncManager.js';

export default class extends Controller {
    static targets = ['banner', 'pendingCount'];

    connect() {
        this._lastSyncTime = 0;
        this.updateStatus();

        this.onlineHandler = () => this.handleOnline();
        this.offlineHandler = () => this.handleOffline();
        this.syncStatusHandler = () => this.updatePendingCount();
        this.swSyncHandler = () => this.triggerSync();

        window.addEventListener('online', this.onlineHandler);
        window.addEventListener('offline', this.offlineHandler);
        window.addEventListener('sync-status-changed', this.syncStatusHandler);
        window.addEventListener('sw-sync-triggered', this.swSyncHandler);

        // Vérifier périodiquement le nombre de BL en attente
        this.updatePendingCount();
        this.pendingInterval = setInterval(() => this.updatePendingCount(), 30000);
    }

    disconnect() {
        window.removeEventListener('online', this.onlineHandler);
        window.removeEventListener('offline', this.offlineHandler);
        window.removeEventListener('sync-status-changed', this.syncStatusHandler);
        window.removeEventListener('sw-sync-triggered', this.swSyncHandler);
        clearInterval(this.pendingInterval);
    }

    updateStatus() {
        if (navigator.onLine) {
            this.handleOnline();
        } else {
            this.handleOffline();
        }
    }

    handleOnline() {
        if (this.hasBannerTarget) {
            this.bannerTarget.classList.add('hidden');
        }
        document.body.classList.remove('offline-mode');

        // Déclencher la synchronisation
        this.triggerSync();
    }

    handleOffline() {
        if (this.hasBannerTarget) {
            this.bannerTarget.classList.remove('hidden');
        }
        document.body.classList.add('offline-mode');
    }

    async updatePendingCount() {
        try {
            const count = await countPendingBLs();
            if (this.hasPendingCountTarget) {
                this.pendingCountTarget.textContent = count;
                const wrapper = this.pendingCountTarget.closest('[data-pending-wrapper]');
                if (wrapper) {
                    wrapper.classList.toggle('hidden', count === 0);
                }
            }

            // Mettre à jour le badge dans le menu aussi
            document.querySelectorAll('[data-pending-badge]').forEach(badge => {
                badge.textContent = count;
                badge.classList.toggle('hidden', count === 0);
            });
        } catch (e) {
            console.error('[NetworkStatus] Erreur comptage:', e);
        }
    }

    async triggerSync() {
        // Debounce : pas de re-trigger dans les 5 secondes
        const now = Date.now();
        if (now - this._lastSyncTime < 5000) {
            return;
        }
        this._lastSyncTime = now;

        try {
            await syncAll();
            await this.updatePendingCount();
        } catch (e) {
            console.error('[NetworkStatus] Erreur sync:', e);
        }
    }
}
