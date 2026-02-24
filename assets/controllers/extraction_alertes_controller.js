import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle(event) {
        const ligneId = event.currentTarget.dataset.ligneId;
        const row = document.getElementById('alertes-' + ligneId);
        if (row) {
            row.classList.toggle('is-visible');
        }
    }
}