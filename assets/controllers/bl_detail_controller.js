import { Controller } from '@hotwired/stimulus';
import { getBLDetail, getBLImage } from '../js/blCacheManager.js';
import { getLastBLSyncTime } from '../js/db.js';

export default class extends Controller {
    static values = { blId: Number };
    static targets = ['header', 'image', 'data', 'loading', 'cacheStatus', 'info', 'lines'];

    async connect() {
        await this.loadBL();
    }

    async loadBL() {
        this.loadingTarget.classList.remove('hidden');
        this.dataTarget.classList.add('hidden');

        try {
            const bl = await getBLDetail(this.blIdValue);

            if (!bl) {
                this.loadingTarget.innerHTML = `
                    <div class="bl-empty-state">
                        <div class="bl-empty-state__icon"><i class="fas fa-exclamation-circle"></i></div>
                        <h3 class="bl-empty-state__title">BL non trouvé</h3>
                        <p class="bl-empty-state__text">Ce bon de livraison n'est pas dans le cache. Vérifiez votre connexion.</p>
                    </div>
                `;
                return;
            }

            this.renderBL(bl);
            this.loadImage(bl);
            this.updateCacheStatus();
        } catch (error) {
            console.error('[BLDetail] Erreur:', error);
            this.loadingTarget.innerHTML = '<p class="text-red-600 text-center">Erreur lors du chargement.</p>';
        }
    }

    renderBL(bl) {
        this.loadingTarget.classList.add('hidden');
        this.dataTarget.classList.remove('hidden');

        // Update page header
        const numeroBl = bl.numeroBl ? `N° ${this.escapeHtml(bl.numeroBl)}` : `#${bl.id}`;
        this.headerTarget.innerHTML = `
            <i class="fas fa-file-invoice mr-2"></i>
            BL ${numeroBl}
        `;

        // Render info section
        this.renderInfo(bl);

        // Render lines table
        this.renderLines(bl);
    }

    renderInfo(bl) {
        const isAnomalie = bl.statut === 'ANOMALIE';
        const badgeClass = isAnomalie ? 'bl-status-badge--anomalie' : 'bl-status-badge--valide';
        const badgeIcon = isAnomalie ? 'exclamation-triangle' : 'check-circle';
        const badgeLabel = isAnomalie ? 'Anomalie' : 'Validé';

        const dateLivraison = bl.dateLivraison
            ? new Date(bl.dateLivraison).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })
            : '—';

        const validatedAt = bl.validatedAt
            ? new Date(bl.validatedAt).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
            : '—';

        const totalHt = bl.totalHt
            ? parseFloat(bl.totalHt).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
            : '—';

        const fournisseur = bl.fournisseur?.nom || '—';
        const etablissement = bl.etablissement?.nom || '—';
        const numeroBl = bl.numeroBl || '—';
        const nbLignes = bl.lignes?.length || 0;
        const nbAlertes = bl.lignes?.reduce((sum, l) => sum + (l.alertes?.length || 0), 0) || 0;

        this.infoTarget.innerHTML = `
            <div class="flex items-center justify-between mb-4">
                <h2 class="bl-detail-header__title">Informations</h2>
                <span class="bl-status-badge ${badgeClass}">
                    <i class="fas fa-${badgeIcon}"></i>
                    ${badgeLabel}
                </span>
            </div>
            <div class="bl-detail-header__grid">
                <div>
                    <div class="bl-detail-header__label">N° BL</div>
                    <div class="bl-detail-header__value">${this.escapeHtml(numeroBl)}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Date livraison</div>
                    <div class="bl-detail-header__value">${dateLivraison}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Fournisseur</div>
                    <div class="bl-detail-header__value">${this.escapeHtml(fournisseur)}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Établissement</div>
                    <div class="bl-detail-header__value">${this.escapeHtml(etablissement)}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Total HT</div>
                    <div class="bl-detail-header__value">${totalHt}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Validé le</div>
                    <div class="bl-detail-header__value">${validatedAt}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Lignes</div>
                    <div class="bl-detail-header__value">${nbLignes}</div>
                </div>
                <div>
                    <div class="bl-detail-header__label">Alertes</div>
                    <div class="bl-detail-header__value">${nbAlertes > 0 ? '<span class="text-red-600">' + nbAlertes + ' alerte(s)</span>' : '0'}</div>
                </div>
            </div>
        `;
    }

    renderLines(bl) {
        const lignes = bl.lignes || [];

        if (lignes.length === 0) {
            this.linesTarget.innerHTML = `
                <div class="bl-detail-table-header">Lignes</div>
                <div class="p-6 text-center text-gray-500">Aucune ligne</div>
            `;
            return;
        }

        const rows = lignes.map(ligne => {
            const hasAlertes = ligne.alertes && ligne.alertes.length > 0;
            const rowClass = hasAlertes ? 'bl-alert-row' : '';

            const alerteHtml = hasAlertes
                ? ligne.alertes.map(a => `
                    <div class="bl-alert-indicator">
                        <i class="fas fa-exclamation-circle"></i>
                        ${this.escapeHtml(a.message)}
                    </div>
                `).join('')
                : '';

            const qty = ligne.quantiteLivree ? parseFloat(ligne.quantiteLivree).toLocaleString('fr-FR') : '—';
            const prix = ligne.prixUnitaire ? parseFloat(ligne.prixUnitaire).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) : '—';
            const total = ligne.totalLigne ? parseFloat(ligne.totalLigne).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €' : '—';
            const unite = ligne.unite || '';

            return `
                <tr class="${rowClass}">
                    <td>
                        ${this.escapeHtml(ligne.designationBl || '—')}
                        ${alerteHtml}
                    </td>
                    <td class="text-right">${qty}${unite ? ' ' + this.escapeHtml(unite) : ''}</td>
                    <td class="text-right">${prix} €</td>
                    <td class="text-right">${total}</td>
                </tr>
            `;
        }).join('');

        this.linesTarget.innerHTML = `
            <div class="bl-detail-table-header">Lignes (${lignes.length})</div>
            <table class="bl-detail-table">
                <thead>
                    <tr>
                        <th>Désignation</th>
                        <th class="text-right">Quantité</th>
                        <th class="text-right">Prix unit.</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        `;
    }

    async loadImage(bl) {
        if (!bl.hasImage) return;

        const blob = await getBLImage(bl.id);
        if (blob) {
            const url = URL.createObjectURL(blob);
            const img = document.createElement('img');
            img.src = url;
            img.alt = `BL ${bl.numeroBl || bl.id}`;
            img.onload = () => URL.revokeObjectURL(url);
            this.imageTarget.innerHTML = '';
            this.imageTarget.appendChild(img);
        }
    }

    async updateCacheStatus() {
        const lastSync = await getLastBLSyncTime();
        const isOnline = navigator.onLine;

        if (!isOnline && lastSync) {
            const timeStr = new Date(lastSync).toLocaleString('fr-FR', {
                day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
            });
            this.cacheStatusTarget.innerHTML = `
                <div class="bl-cache-banner bl-cache-banner--offline">
                    <i class="fas fa-wifi-slash"></i>
                    <span>Mode hors connexion — Données en cache</span>
                    <span class="bl-cache-banner__time">— Sync : ${timeStr}</span>
                </div>
            `;
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
