import { Controller } from '@hotwired/stimulus';
import { countPendingBLs } from '../js/db.js';

export default class extends Controller {
    static targets = ['banner', 'pendingCount'];

    connect() {
        this.updateStatus();

        this.onlineHandler = () => this.handleOnline();
        this.offlineHandler = () => this.handleOffline();

        window.addEventListener('online', this.onlineHandler);
        window.addEventListener('offline', this.offlineHandler);

        // Vérifier périodiquement le nombre de BL en attente
        this.updatePendingCount();
        this.pendingInterval = setInterval(() => this.updatePendingCount(), 30000);
    }

    disconnect() {
        window.removeEventListener('online', this.onlineHandler);
        window.removeEventListener('offline', this.offlineHandler);
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
        // On implémentera le Background Sync au Sprint 3
        // Pour l'instant, juste un log
        console.log('[NetworkStatus] Réseau disponible — sync à implémenter Sprint 3');
    }
}