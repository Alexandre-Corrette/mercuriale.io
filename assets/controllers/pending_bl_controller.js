import { Controller } from '@hotwired/stimulus';
import { db, getPendingBLs, deletePendingBL, updateBLStatus, checkStorageQuota, BL_STATUS } from '../js/db.js';
import { syncAll as syncManagerSyncAll, syncOne as syncManagerSyncOne } from '../js/syncManager.js';

export default class extends Controller {
    static targets = ['list', 'count', 'emptyState', 'actions', 'networkStatus', 'storageQuota'];

    async connect() {
        await this.loadPendingBLs();
        this.updateNetworkStatus();
        this.updateStorageQuota();

        // Écouter les changements de réseau
        this.onlineHandler = () => this.updateNetworkStatus();
        this.offlineHandler = () => this.updateNetworkStatus();
        this.syncStatusHandler = () => this.loadPendingBLs();
        window.addEventListener('online', this.onlineHandler);
        window.addEventListener('offline', this.offlineHandler);
        window.addEventListener('sync-status-changed', this.syncStatusHandler);

        // Rafraîchir toutes les 10 secondes
        this.refreshInterval = setInterval(() => this.loadPendingBLs(), 10000);
    }

    disconnect() {
        window.removeEventListener('online', this.onlineHandler);
        window.removeEventListener('offline', this.offlineHandler);
        window.removeEventListener('sync-status-changed', this.syncStatusHandler);
        clearInterval(this.refreshInterval);
    }

    async loadPendingBLs() {
        try {
            const bls = await getPendingBLs();

            // Récupérer les photos associées
            const blsWithPhotos = await Promise.all(bls.map(async (bl) => {
                const photos = await db.pendingPhotos.where('pendingBLId').equals(bl.id).toArray();
                return { ...bl, photos };
            }));

            this.renderList(blsWithPhotos);
            this.countTarget.textContent = bls.length;

            // Afficher/masquer états
            this.emptyStateTarget.classList.toggle('hidden', bls.length > 0);
            this.listTarget.classList.toggle('hidden', bls.length === 0);
            this.actionsTarget.classList.toggle('hidden', bls.length === 0);

            // Charger les miniatures
            this.loadThumbnails();

        } catch (error) {
            console.error('[PendingBL] Erreur chargement:', error);
        }
    }

    renderList(bls) {
        this.listTarget.innerHTML = bls.map(bl => this.renderBLCard(bl)).join('');
    }

    renderBLCard(bl) {
        const statusConfig = {
            [BL_STATUS.PENDING]: { icon: 'clock', color: 'amber', label: 'En attente' },
            [BL_STATUS.UPLOADING]: { icon: 'spinner fa-spin', color: 'blue', label: 'Envoi...' },
            [BL_STATUS.UPLOADED]: { icon: 'cloud-upload-alt', color: 'blue', label: 'OCR en cours' },
            [BL_STATUS.FAILED]: { icon: 'exclamation-triangle', color: 'red', label: 'Échec' },
            [BL_STATUS.SYNCED]: { icon: 'check', color: 'green', label: 'Synchronisé' }
        };

        const status = statusConfig[bl.status] || statusConfig[BL_STATUS.PENDING];
        const photo = bl.photos?.[0];
        const date = new Date(bl.createdAt).toLocaleString('fr-FR', {
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
        });

        // Créer un placeholder pour la miniature
        let thumbnailHtml = '<div class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center"><i class="fas fa-image text-gray-400"></i></div>';
        if (photo?.blob) {
            thumbnailHtml = `<div class="w-16 h-16 bg-gray-200 rounded-lg overflow-hidden" data-photo-id="${photo.id}">
                <img src="" alt="BL" class="w-full h-full object-cover" data-thumbnail-photo-id="${photo.id}">
            </div>`;
        }

        return `
            <div class="bg-white rounded-xl shadow-lg overflow-hidden" data-bl-id="${bl.id}">
                <div class="p-4 flex items-center gap-4">
                    ${thumbnailHtml}
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-navy truncate">
                            ${bl.etablissementNom || 'Établissement #' + bl.etablissementId}
                        </div>
                        <div class="text-sm text-gray-500">
                            ${bl.fournisseurNom || 'Fournisseur à déterminer'}
                        </div>
                        <div class="text-xs text-gray-400 mt-1">
                            ${date}
                            ${photo ? ` • ${(photo.size / 1024).toFixed(0)} Ko` : ''}
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="px-3 py-1 rounded-full text-xs font-medium bg-${status.color}-100 text-${status.color}-700">
                            <i class="fas fa-${status.icon} mr-1"></i>
                            ${status.label}
                        </span>
                        <button type="button"
                                data-action="pending-bl#delete"
                                data-bl-id="${bl.id}"
                                class="p-2 text-gray-400 hover:text-red-500 transition-colors"
                                title="Supprimer">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                ${bl.status === BL_STATUS.FAILED ? `
                    <div class="px-4 py-2 bg-red-50 border-t border-red-100 flex items-center justify-between">
                        <span class="text-sm text-red-600">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            Échec de l'envoi (${bl.retryCount || 0} tentatives)
                        </span>
                        <button type="button"
                                data-action="pending-bl#retry"
                                data-bl-id="${bl.id}"
                                class="text-sm text-red-600 hover:text-red-800 font-medium">
                            Réessayer
                        </button>
                    </div>
                ` : ''}
            </div>
        `;
    }

    async loadThumbnails() {
        // Charger les miniatures depuis IndexedDB
        const thumbnails = this.element.querySelectorAll('[data-thumbnail-photo-id]');
        for (const img of thumbnails) {
            const photoId = parseInt(img.dataset.thumbnailPhotoId);
            try {
                const photo = await db.pendingPhotos.get(photoId);
                if (photo?.blob) {
                    const url = URL.createObjectURL(photo.blob);
                    img.src = url;
                    // Libérer l'URL après chargement
                    img.onload = () => URL.revokeObjectURL(url);
                }
            } catch (e) {
                console.error('[PendingBL] Erreur chargement miniature:', e);
            }
        }
    }

    updateNetworkStatus() {
        const isOnline = navigator.onLine;
        this.networkStatusTarget.innerHTML = isOnline ? `
            <div class="flex items-center gap-3 p-4 bg-green-50 text-green-800 border border-green-200 rounded-xl">
                <i class="fas fa-wifi"></i>
                <span>Connecté — La synchronisation est active</span>
            </div>
        ` : `
            <div class="flex items-center gap-3 p-4 bg-amber-50 text-amber-800 border border-amber-200 rounded-xl">
                <i class="fas fa-wifi-slash"></i>
                <span>Hors connexion — Les BL seront envoyés au retour du réseau</span>
            </div>
        `;
    }

    async updateStorageQuota() {
        const quota = await checkStorageQuota();
        if (!quota) return;

        const usedMB = (quota.used / 1024 / 1024).toFixed(1);
        const quotaMB = (quota.quota / 1024 / 1024).toFixed(0);

        this.storageQuotaTarget.innerHTML = quota.warning ? `
            <div class="flex items-center gap-3 p-4 bg-red-50 text-red-800 border border-red-200 rounded-xl">
                <i class="fas fa-database"></i>
                <span>Stockage presque plein : ${usedMB} Mo / ${quotaMB} Mo (${quota.percentUsed}%)</span>
            </div>
        ` : `
            <div class="text-sm text-gray-500">
                <i class="fas fa-database mr-1"></i>
                Stockage utilisé : ${usedMB} Mo / ${quotaMB} Mo (${quota.percentUsed}%)
            </div>
        `;
    }

    async delete(event) {
        const blId = parseInt(event.currentTarget.dataset.blId);
        if (!confirm('Supprimer ce bon de livraison en attente ?')) return;

        await deletePendingBL(blId);
        await this.loadPendingBLs();
    }

    async deleteAll() {
        if (!confirm('Supprimer TOUS les bons de livraison en attente ? Cette action est irréversible.')) return;

        const bls = await getPendingBLs();
        for (const bl of bls) {
            await deletePendingBL(bl.id);
        }
        await this.loadPendingBLs();
    }

    async retry(event) {
        const blId = parseInt(event.currentTarget.dataset.blId);

        if (!navigator.onLine) {
            await updateBLStatus(blId, BL_STATUS.PENDING);
            await this.loadPendingBLs();
            return;
        }

        await syncManagerSyncOne(blId);
        await this.loadPendingBLs();
    }

    async syncAll() {
        if (!navigator.onLine) {
            return;
        }

        await syncManagerSyncAll();
        await this.loadPendingBLs();
    }
}
