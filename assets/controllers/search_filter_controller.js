import { Controller } from '@hotwired/stimulus';

/**
 * Generic search filter for any list page.
 *
 * Usage:
 *   <div data-controller="search-filter">
 *     <input data-search-filter-target="input" ...>
 *     <div data-search-filter-target="list">
 *       <a data-search-filter-target="item" data-search="text to match">...</a>
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'list', 'item'];

    filter() {
        const query = this.inputTarget.value.toLowerCase().trim();

        this.itemTargets.forEach((item) => {
            const text = (item.dataset.search || item.textContent).toLowerCase();
            item.style.display = text.includes(query) ? '' : 'none';
        });
    }
}
