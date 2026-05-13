import { Controller } from '@hotwired/stimulus';

/**
 * Extraction Controller
 *
 * Lance l'extraction OCR async puis poll le statut jusqu'à completion.
 * Évite le reload prématuré qui montrerait encore le bouton "Lancer l'extraction"
 * alors que le worker n'a pas fini.
 *
 * Usage:
 *   <div data-controller="extraction"
 *        data-extraction-url-value="/app/bl/123/extraire"
 *        data-extraction-status-url-value="/app/bl/123/status">
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
        url: String,
        statusUrl: String,
        autoPoll: { type: Boolean, default: false },
        pollIntervalMs: { type: Number, default: 5000 },
        pollTimeoutMs: { type: Number, default: 180000 }, // 3 minutes max
    };

    connect() {
        // Si la page est servie pendant une extraction déjà en cours côté serveur,
        // on démarre le polling immédiatement (pas besoin de cliquer "Lancer").
        if (this.autoPollValue) {
            this.startPolling();
        }
    }

    disconnect() {
        this.stopPolling();
    }

    async start(event) {
        event.preventDefault();

        if (!this.urlValue) {
            console.error('[Extraction] URL not configured');
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
                // Le worker traite en async (peut prendre 30-60s).
                // On poll le statut au lieu de reload immédiatement.
                this.startPolling();
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

    startPolling() {
        if (!this.statusUrlValue) {
            console.warn('[Extraction] No status URL — falling back to immediate reload');
            window.location.reload();
            return;
        }

        const startedAt = Date.now();

        this.pollTimer = setInterval(async () => {
            if (Date.now() - startedAt > this.pollTimeoutMsValue) {
                this.stopPolling();
                alert("L'extraction prend plus de temps que prévu. Rafraichissez la page dans quelques instants.");
                return;
            }

            try {
                const response = await fetch(this.statusUrlValue, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                // Le backend retourne un JSON 404 propre quand le BL n'existe plus
                // (suppression, ex. détection de doublon par le worker OCR).
                // On va chercher la notification pending (déposée par l'event listener
                // côté worker) pour expliquer au user pourquoi son BL a disparu.
                if (response.status === 404) {
                    this.stopPolling();
                    await this.handleDeletedBl(response);
                    return;
                }
                if (!response.ok) return; // 5xx ou autres : tente le prochain tick

                const data = await response.json();
                // Si le statut a quitté EN_COURS_OCR, l'extraction est finie
                // (VALIDE, ANOMALIE, ECHEC_OCR, etc.) — on reload pour afficher le résultat.
                if (data.statut !== 'EN_COURS_OCR') {
                    this.stopPolling();
                    window.location.reload();
                }
            } catch (e) {
                console.warn('[Extraction] Poll failed:', e);
                // on retente au prochain tick
            }
        }, this.pollIntervalMsValue);
    }

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    async handleDeletedBl(statusResponse) {
        // Tente d'abord de récupérer une notification dédiée (déposée par l'event
        // listener côté worker quand un doublon est détecté). Si absente, fallback
        // sur le payload du status.
        let message = null;
        let redirect = '/app/bl';

        try {
            const notifResponse = await fetch('/app/notifications/pending', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (notifResponse.ok) {
                const notifData = await notifResponse.json();
                if (notifData.notification) {
                    message = notifData.notification.message;
                    if (notifData.notification.original_bl_id) {
                        redirect = `/app/bl/${notifData.notification.original_bl_id}/extraction`;
                    }
                }
            }
        } catch (e) {
            console.warn('[Extraction] Échec récupération notification:', e);
        }

        if (!message) {
            try {
                const data = await statusResponse.json();
                message = data.message;
                redirect = data.redirect || redirect;
            } catch (e) {
                // pas de payload exploitable
            }
        }

        if (message) {
            sessionStorage.setItem('flash_warning', message);
        }
        window.location.href = redirect;
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
