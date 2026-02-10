import { Controller } from '@hotwired/stimulus';
import { countPendingBLs } from '../js/db.js';
import { syncAll } from '../js/syncManager.js';
import { isOnline, invalidateProbe } from '../js/networkProbe.js';

export default class extends Controller {
    static targets = ['banner', 'pendingCount'];

    connect() {
        this._lastSyncTime = 0;
        this._probeInterval = null;
        this.updateStatus();

        this.onlineHandler = () => { invalidateProbe(); this.handleOnline(); };
        this.offlineHandler = () => { invalidateProbe(); this.handleOffline(); };
        this.syncStatusHandler = () => this.updatePendingCount();
        this.swSyncHandler = () => this.triggerSync();
        this.authLostHandler = () => this.showAuthLostBanner();
        this.quotaCriticalHandler = () => this.showQuotaCriticalBanner();
        this.visibilityHandler = () => this.onVisibilityChange();

        window.addEventListener('online', this.onlineHandler);
        window.addEventListener('offline', this.offlineHandler);
        window.addEventListener('sync-status-changed', this.syncStatusHandler);
        window.addEventListener('sw-sync-triggered', this.swSyncHandler);
        window.addEventListener('auth-session-lost', this.authLostHandler);
        window.addEventListener('storage-quota-critical', this.quotaCriticalHandler);
        document.addEventListener('visibilitychange', this.visibilityHandler);

        // Vérifier périodiquement le nombre de BL en attente
        this.updatePendingCount();
        this.pendingInterval = setInterval(() => this.updatePendingCount(), 30000);

        // Periodic network probe (30s) — skip when tab hidden
        this.startProbeInterval();
    }

    disconnect() {
        window.removeEventListener('online', this.onlineHandler);
        window.removeEventListener('offline', this.offlineHandler);
        window.removeEventListener('sync-status-changed', this.syncStatusHandler);
        window.removeEventListener('sw-sync-triggered', this.swSyncHandler);
        window.removeEventListener('auth-session-lost', this.authLostHandler);
        window.removeEventListener('storage-quota-critical', this.quotaCriticalHandler);
        document.removeEventListener('visibilitychange', this.visibilityHandler);
        clearInterval(this.pendingInterval);
        this.stopProbeInterval();
    }

    startProbeInterval() {
        this.stopProbeInterval();
        this._probeInterval = setInterval(() => {
            if (!document.hidden) {
                this.probeAndUpdate();
            }
        }, 30000);
    }

    stopProbeInterval() {
        if (this._probeInterval) {
            clearInterval(this._probeInterval);
            this._probeInterval = null;
        }
    }

    onVisibilityChange() {
        if (!document.hidden) {
            this.probeAndUpdate();
        }
    }

    async probeAndUpdate() {
        const online = await isOnline();
        if (online) {
            this.handleOnline();
        } else {
            this.handleOffline();
        }
    }

    async updateStatus() {
        const online = await isOnline();
        if (online) {
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

    showAuthLostBanner() {
        if (this.hasBannerTarget) {
            this.bannerTarget.classList.remove('hidden');
            this.bannerTarget.innerHTML =
                '<i class="fas fa-lock mr-2"></i>' +
                'Session expirée — <a href="/login" class="underline hover:no-underline">Reconnectez-vous</a>';
        }
    }

    showQuotaCriticalBanner() {
        if (this.hasBannerTarget) {
            this.bannerTarget.classList.remove('hidden');
            this.bannerTarget.innerHTML =
                '<i class="fas fa-database mr-2"></i>' +
                'Stockage critique — Supprimez des données pour continuer';
            this.bannerTarget.classList.remove('bg-amber-500');
            this.bannerTarget.classList.add('bg-red-600');
        }
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
