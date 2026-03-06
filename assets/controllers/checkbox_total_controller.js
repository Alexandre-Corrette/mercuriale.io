import { Controller } from '@hotwired/stimulus';

/**
 * Checkbox select-all + live total computation.
 *
 * Usage:
 *   <div data-controller="checkbox-total">
 *     <input type="checkbox" data-checkbox-total-target="selectAll"
 *            data-action="change->checkbox-total#toggleAll">
 *     <tr data-montant="12.50">
 *       <td><input type="checkbox" data-checkbox-total-target="checkbox"
 *                  data-action="change->checkbox-total#update"></td>
 *     </tr>
 *     <span data-checkbox-total-target="total"></span>
 *   </div>
 */
export default class extends Controller {
    static targets = ['selectAll', 'checkbox', 'total'];

    connect() {
        this.update();
    }

    toggleAll() {
        const checked = this.selectAllTarget.checked;
        this.checkboxTargets.forEach((cb) => { cb.checked = checked; });
        this.update();
    }

    update() {
        let total = 0;
        this.checkboxTargets.forEach((cb) => {
            if (cb.checked) {
                const row = cb.closest('tr');
                total += parseFloat(row?.dataset.montant) || 0;
            }
        });

        this.totalTarget.innerHTML = total
            .toFixed(2)
            .replace('.', ',')
            .replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' &euro;';

        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = this.checkboxTargets.every((cb) => cb.checked);
        }
    }
}
