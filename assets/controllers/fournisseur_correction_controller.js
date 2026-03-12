import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['name', 'editBtn', 'dropdown', 'select', 'status'];
    static values = { url: String };

    toggle() {
        this.dropdownTarget.classList.toggle('hidden');
        this.editBtnTarget.classList.toggle('hidden');
    }

    async save() {
        var fournisseurId = this.selectTarget.value;
        var statusEl = this.statusTarget;

        statusEl.classList.remove('hidden');
        statusEl.textContent = 'Enregistrement...';
        statusEl.className = 'extraction-fournisseur__status';

        try {
            var response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    fournisseur_id: fournisseurId || null,
                }),
            });

            var data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Erreur serveur');
            }

            // Update displayed name
            if (data.fournisseur) {
                this.nameTarget.innerHTML =
                    '<i class="fas fa-check-circle extraction-fournisseur__icon--ok"></i> ' +
                    data.fournisseur.nom;
                this.nameTarget.classList.remove('extraction-fournisseur__name--missing');
            } else {
                this.nameTarget.innerHTML =
                    '<i class="fas fa-exclamation-triangle extraction-fournisseur__icon--warn"></i> Non identifie';
                this.nameTarget.classList.add('extraction-fournisseur__name--missing');
            }

            statusEl.textContent = 'Enregistre';
            statusEl.classList.add('extraction-fournisseur__status--ok');

            setTimeout(() => {
                this.dropdownTarget.classList.add('hidden');
                this.editBtnTarget.classList.remove('hidden');
                statusEl.classList.add('hidden');
            }, 1200);
        } catch (err) {
            statusEl.textContent = err.message;
            statusEl.classList.add('extraction-fournisseur__status--error');
        }
    }
}
