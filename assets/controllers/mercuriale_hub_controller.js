import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { searchUrl: String };
    static targets = ['searchInput', 'fournisseurFilter', 'dateFrom', 'dateTo', 'loading', 'results', 'pagination'];

    connect() {
        this._debounceTimer = null;
        this._currentFournisseur = null;
        this._currentPage = 1;

        this.fetchMercuriales();
    }

    disconnect() {
        if (this._debounceTimer) {
            clearTimeout(this._debounceTimer);
        }
    }

    onSearchInput() {
        if (this._debounceTimer) {
            clearTimeout(this._debounceTimer);
        }
        this._debounceTimer = setTimeout(() => {
            this._currentPage = 1;
            this.fetchMercuriales();
        }, 300);
    }

    selectFournisseur(event) {
        const card = event.currentTarget;
        const id = parseInt(card.dataset.fournisseurId);

        if (this._currentFournisseur === id) {
            this._currentFournisseur = null;
            card.classList.remove('hub-fournisseur-card--active');
        } else {
            this.element.querySelectorAll('.hub-fournisseur-card--active').forEach(el =>
                el.classList.remove('hub-fournisseur-card--active')
            );
            this._currentFournisseur = id;
            card.classList.add('hub-fournisseur-card--active');
        }

        // Sync dropdown
        if (this.hasFournisseurFilterTarget) {
            this.fournisseurFilterTarget.value = this._currentFournisseur || '';
        }

        this._currentPage = 1;
        this.fetchMercuriales();
    }

    onFournisseurDropdown() {
        const value = this.fournisseurFilterTarget.value;
        this._currentFournisseur = value ? parseInt(value) : null;

        // Sync cards
        this.element.querySelectorAll('.hub-fournisseur-card--active').forEach(el =>
            el.classList.remove('hub-fournisseur-card--active')
        );
        if (this._currentFournisseur) {
            const activeCard = this.element.querySelector(`[data-fournisseur-id="${this._currentFournisseur}"]`);
            if (activeCard) activeCard.classList.add('hub-fournisseur-card--active');
        }

        this._currentPage = 1;
        this.fetchMercuriales();
    }

    onDateChange() {
        this._currentPage = 1;
        this.fetchMercuriales();
    }

    goToPage(event) {
        const page = parseInt(event.currentTarget.dataset.page);
        if (page && page !== this._currentPage) {
            this._currentPage = page;
            this.fetchMercuriales();
        }
    }

    async fetchMercuriales() {
        this.loadingTarget.classList.remove('hub-loading--hidden');
        this.resultsTarget.innerHTML = '';
        this.paginationTarget.innerHTML = '';

        const params = new URLSearchParams();
        const query = this.hasSearchInputTarget ? this.searchInputTarget.value.trim() : '';
        if (query) params.set('q', query);
        if (this._currentFournisseur) params.set('fournisseur', this._currentFournisseur);

        if (this.hasDateFromTarget && this.dateFromTarget.value) {
            params.set('date_from', this.dateFromTarget.value);
        }
        if (this.hasDateToTarget && this.dateToTarget.value) {
            params.set('date_to', this.dateToTarget.value);
        }

        params.set('page', this._currentPage);

        try {
            const response = await fetch(`${this.searchUrlValue}?${params.toString()}`);
            if (!response.ok) throw new Error('Network error');
            const data = await response.json();

            this.renderResults(data);
            this.renderPagination(data);
        } catch (error) {
            console.error('[MercurialeHub] Fetch error:', error);
            this.resultsTarget.innerHTML = '<div class="hub-table__empty">Erreur lors du chargement.</div>';
        } finally {
            this.loadingTarget.classList.add('hub-loading--hidden');
        }
    }

    renderResults(data) {
        if (data.items.length === 0) {
            this.resultsTarget.innerHTML = '<div class="hub-table__empty">Aucun prix trouvé.</div>';
            return;
        }

        const rows = data.items.map(item => {
            const scopeBadge = item.scope
                ? `<span class="hub-badge hub-badge--warning">${this.escapeHtml(item.scope)}</span>`
                : '<span class="hub-badge hub-badge--secondary">Groupe</span>';

            return `
                <tr>
                    <td>${this.escapeHtml(item.designation)}</td>
                    <td>${this.escapeHtml(item.fournisseur)}</td>
                    <td class="hub-prix">${this.formatPrice(item.prix)} &euro;</td>
                    <td>${this.escapeHtml(item.unite)}</td>
                    <td>${this.escapeHtml(item.dateDebut)}</td>
                    <td>${item.dateFin ? this.escapeHtml(item.dateFin) : '—'}</td>
                    <td>${scopeBadge}</td>
                </tr>
            `;
        }).join('');

        this.resultsTarget.innerHTML = `
            <table class="hub-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Fournisseur</th>
                        <th>Prix</th>
                        <th>Unité</th>
                        <th>Début</th>
                        <th>Fin</th>
                        <th>Scope</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
            <div class="hub-pagination__info">${data.total} prix trouvé${data.total > 1 ? 's' : ''}</div>
        `;
    }

    renderPagination(data) {
        if (data.pages <= 1) return;

        let buttons = '';

        buttons += `<button class="hub-pagination__btn" data-action="mercuriale-hub#goToPage" data-page="${data.page - 1}" ${data.page <= 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>`;

        const start = Math.max(1, data.page - 2);
        const end = Math.min(data.pages, data.page + 2);

        for (let i = start; i <= end; i++) {
            const active = i === data.page ? ' hub-pagination__btn--active' : '';
            buttons += `<button class="hub-pagination__btn${active}" data-action="mercuriale-hub#goToPage" data-page="${i}">${i}</button>`;
        }

        buttons += `<button class="hub-pagination__btn" data-action="mercuriale-hub#goToPage" data-page="${data.page + 1}" ${data.page >= data.pages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>`;

        this.paginationTarget.innerHTML = buttons;
    }

    formatPrice(price) {
        return new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 4,
            maximumFractionDigits: 4,
        }).format(price);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
