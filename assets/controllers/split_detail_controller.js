import { Controller } from '@hotwired/stimulus';

/**
 * Split-view: click an item in a list to load its detail via fetch.
 *
 * Usage:
 *   <div data-controller="split-detail">
 *     <div data-split-detail-target="item"
 *          data-action="click->split-detail#select"
 *          data-url="/detail/123">...</div>
 *     <div data-split-detail-target="detail">
 *       <!-- detail loaded here -->
 *     </div>
 *   </div>
 *
 * Values:
 *   data-split-detail-active-class-value  — CSS class for selected item (default: "--active" suffix)
 *   data-split-detail-loading-html-value  — HTML shown while loading
 *   data-split-detail-error-html-value    — HTML shown on error
 */
export default class extends Controller {
    static targets = ['item', 'detail'];
    static values = {
        loadingHtml: { type: String, default: '<div style="text-align:center;padding:2rem"><i class="fas fa-spinner fa-spin"></i></div>' },
        errorHtml: { type: String, default: '<div style="text-align:center;padding:2rem;color:#ef4444"><i class="fas fa-exclamation-triangle"></i> Erreur de chargement</div>' },
    };

    select(event) {
        const item = event.currentTarget;
        const url = item.dataset.url;
        if (!url) return;

        // Update active state
        this.itemTargets.forEach((i) => {
            // Remove any class ending with --active
            i.classList.forEach((cls) => {
                if (cls.endsWith('--active')) i.classList.remove(cls);
            });
        });

        // Add active class based on the item's first BEM class
        const baseClass = item.classList[0];
        if (baseClass) {
            item.classList.add(baseClass + '--active');
        }

        // Load detail
        this.detailTarget.innerHTML = this.loadingHtmlValue;

        fetch(url, { credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) throw new Error('Erreur ' + response.status);
                return response.text();
            })
            .then((html) => {
                this.detailTarget.innerHTML = html;
            })
            .catch(() => {
                this.detailTarget.innerHTML = this.errorHtmlValue;
            });
    }
}