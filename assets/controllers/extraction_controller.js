import { Controller } from '@hotwired/stimulus';

/**
 * Extraction Controller
 *
 * Handles async OCR extraction via API calls.
 *
 * Usage:
 *   <div data-controller="extraction" data-extraction-url-value="/api/extract/123">
 *       <div data-extraction-target="placeholder">
 *           <button data-action="extraction#start">Lancer l'extraction</button>
 *       </div>
 *       <div data-extraction-target="loading" class="hidden">
 *           Extraction en cours...
 *       </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['placeholder', 'loading'];

    static values = {
        url: String
    };

    async start(event) {
        event.preventDefault();

        if (!this.urlValue) {
            console.error('Extraction URL not configured');
            return;
        }

        this.showLoading();

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                if (data.redirectUrl) {
                    window.location.href = data.redirectUrl;
                } else {
                    window.location.reload();
                }
            } else {
                this.showPlaceholder();
                const errors = data.errors || ['Une erreur est survenue'];
                alert('Erreur lors de l\'extraction:\n' + errors.join('\n'));
            }
        } catch (error) {
            this.showPlaceholder();
            alert('Erreur de connexion: ' + error.message);
        }
    }

    showLoading() {
        if (this.hasPlaceholderTarget) {
            this.placeholderTarget.classList.add('hidden');
        }
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.remove('hidden');
        }
    }

    showPlaceholder() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.add('hidden');
        }
        if (this.hasPlaceholderTarget) {
            this.placeholderTarget.classList.remove('hidden');
        }
    }
}
