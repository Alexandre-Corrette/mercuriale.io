import { Controller } from '@hotwired/stimulus';
import { getBLList, refreshBLCache } from '../js/blCacheManager.js';
import { getCachedReferentiels, getLastBLSyncTime } from '../js/db.js';

export default class extends Controller {
    static targets = ['list', 'count', 'emptyState', 'loading', 'cacheStatus', 'filter', 'refreshBtn'];

    async connect() {
        this._selectedEtablissement = null;

        await this.populateFilter();
        await this.loadBLs();

        this.onlineHandler = () => this.onOnline();
        this.offlineHandler = () => this.updateCacheStatus();
        this.cacheUpdatedHandler = () => this.loadBLsFromCache();
        window.addEventListener('online', this.onlineHandler);
        window.addEventListener('offline', this.offlineHandler);
        window.addEventListener('bl-cache-updated', this.cacheUpdatedHandler);

        // Auto-refresh every 5 minutes when visible
        this.autoRefreshInterval = setInterval(() => {
            if (!document.hidden && navigator.onLine) {
                this.loadBLs();
            }
        }, 5 * 60 * 1000);
    }

    disconnect() {
        window.removeEventListener('online', this.onlineHandler);
        window.removeEventListener('offline', this.offlineHandler);
        window.removeEventListener('bl-cache-updated', this.cacheUpdatedHandler);
        clearInterval(this.autoRefreshInterval);
    }

    async onOnline() {
        await this.loadBLs();
        this.updateCacheStatus();
    }

    async populateFilter() {
        const etablissements = await getCachedReferentiels('etablissements');
        if (!etablissements || !Array.isArray(etablissements)) return;

        const select = this.filterTarget;
        etablissements.forEach(etab => {
            const option = document.createElement('option');
            option.value = etab.id;
            option.textContent = etab.nom;
            select.appendChild(option);
        });
    }

    async filterChanged() {
        const value = this.filterTarget.value;
        this._selectedEtablissement = value ? parseInt(value) : null;
        await this.loadBLs();
    }

    async refresh() {
        this.refreshBtnTarget.disabled = true;
        const icon = this.refreshBtnTarget.querySelector('i');
        icon.classList.add('fa-spin');

        try {
            await refreshBLCache({ force: true, etablissementId: this._selectedEtablissement });
            await this.loadBLsFromCache();
        } finally {
            this.refreshBtnTarget.disabled = false;
            icon.classList.remove('fa-spin');
        }
    }

    async loadBLs() {
        this.loadingTarget.classList.remove('hidden');
        this.listTarget.classList.add('hidden');
        this.emptyStateTarget.classList.add('hidden');

        try {
            const bls = await getBLList(this._selectedEtablissement);
            this.renderList(bls);
        } catch (error) {
            console.error('[BLList] Erreur chargement:', error);
            // Try from cache only
            await this.loadBLsFromCache();
        }
    }

    async loadBLsFromCache() {
        try {
            const { getCachedBLs } = await import('../js/db.js');
            const bls = await getCachedBLs(this._selectedEtablissement);
            this.renderList(bls);
        } catch (error) {
            console.error('[BLList] Erreur cache:', error);
        }
    }

    renderList(bls) {
        this.loadingTarget.classList.add('hidden');
        this.countTarget.textContent = bls.length;

        if (bls.length === 0) {
            this.emptyStateTarget.classList.remove('hidden');
            this.listTarget.classList.add('hidden');
        } else {
            this.emptyStateTarget.classList.add('hidden');
            this.listTarget.classList.remove('hidden');
            this.listTarget.innerHTML = bls.map(bl => this.renderCard(bl)).join('');
        }

        this.updateCacheStatus();
    }

    renderCard(bl) {
        const statut = bl.statut;
        const isAnomalie = statut === 'ANOMALIE';
        const badgeClass = isAnomalie ? 'bl-status-badge--anomalie' : 'bl-status-badge--valide';
        const badgeIcon = isAnomalie ? 'exclamation-triangle' : 'check-circle';
        const badgeLabel = isAnomalie ? 'Anomalie' : 'Validé';

        const date = bl.dateLivraison
            ? new Date(bl.dateLivraison).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
            : '';

        const fournisseur = bl.fournisseur?.nom || 'Fournisseur inconnu';
        const etablissement = bl.etablissement?.nom || '';
        const numeroBl = bl.numeroBl || '';
        const totalHt = bl.totalHt ? parseFloat(bl.totalHt).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }) : '';

        return `
            <a href="/app/bons-livraison/${bl.id}" class="bl-card">
                <div class="bl-card__inner">
                    <div class="bl-card__body">
                        <div class="bl-card__fournisseur">${this.escapeHtml(fournisseur)}</div>
                        <div class="bl-card__meta">
                            ${this.escapeHtml(etablissement)}${numeroBl ? ' — N° ' + this.escapeHtml(numeroBl) : ''}${date ? ' — ' + date : ''}
                        </div>
                        ${totalHt ? `<div class="bl-card__total">${totalHt}</div>` : ''}
                    </div>
                    <div class="bl-card__right">
                        <span class="bl-status-badge ${badgeClass}">
                            <i class="fas fa-${badgeIcon}"></i>
                            ${badgeLabel}
                        </span>
                        <span class="bl-card__chevron"><i class="fas fa-chevron-right"></i></span>
                    </div>
                </div>
            </a>
        `;
    }

    async updateCacheStatus() {
        const lastSync = await getLastBLSyncTime();
        const isOnline = navigator.onLine;

        if (!lastSync) {
            this.cacheStatusTarget.innerHTML = '';
            return;
        }

        const syncDate = new Date(lastSync);
        const age = Date.now() - syncDate.getTime();
        const timeStr = syncDate.toLocaleString('fr-FR', {
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
        });

        let bannerClass, icon, text;

        if (!isOnline) {
            bannerClass = 'bl-cache-banner--offline';
            icon = 'wifi-slash';
            text = 'Mode hors connexion — Données en cache';
        } else if (age > 10 * 60 * 1000) {
            bannerClass = 'bl-cache-banner--stale';
            icon = 'clock';
            text = 'Données en cache';
        } else {
            bannerClass = 'bl-cache-banner--fresh';
            icon = 'check-circle';
            text = 'Données à jour';
        }

        this.cacheStatusTarget.innerHTML = `
            <div class="bl-cache-banner ${bannerClass}">
                <i class="fas fa-${icon}"></i>
                <span>${text}</span>
                <span class="bl-cache-banner__time">— Dernière sync : ${timeStr}</span>
            </div>
        `;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
