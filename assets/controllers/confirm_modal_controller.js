import { Controller } from '@hotwired/stimulus';

/**
 * Confirm Modal Controller
 *
 * Reusable confirmation dialog. Triggered from any element with:
 *   data-action="confirm-modal#open"
 *   data-confirm-modal-title-param="..."
 *   data-confirm-modal-message-param="..."
 *   data-confirm-modal-confirm-label-param="..."
 *   data-confirm-modal-variant-param="danger|warning"
 *   data-confirm-modal-url-param="..." (optional — navigates on confirm)
 */
export default class extends Controller {
    static targets = ['overlay', 'title', 'message', 'icon', 'confirmBtn'];

    /** @type {string|null} */
    _confirmUrl = null;

    /** @type {HTMLElement|null} */
    _triggerElement = null;

    /** @type {Function|null} */
    _onKeydown = null;

    connect() {
        this._onKeydown = this._handleKeydown.bind(this);
    }

    disconnect() {
        this._removeKeyListener();
    }

    /**
     * Open modal (action from trigger element)
     * @param {Event} event
     */
    open(event) {
        event.preventDefault();
        const { params } = event;

        this._triggerElement = event.currentTarget;
        this._confirmUrl = params.url || null;

        // Update content
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = params.title || 'Confirmer';
        }
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = params.message || 'Etes-vous sur ?';
        }
        if (this.hasConfirmBtnTarget) {
            this.confirmBtnTarget.textContent = params.confirmLabel || 'Confirmer';

            // Update button variant
            const variant = params.variant || 'danger';
            this.confirmBtnTarget.className = `btn btn--${variant}`;
        }

        // Show
        this.overlayTarget.classList.add('is-open');
        this.overlayTarget.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // Focus trap
        document.addEventListener('keydown', this._onKeydown);
        this.confirmBtnTarget.focus();
    }

    /**
     * Confirm action
     */
    confirm() {
        this._close();

        if (this._confirmUrl) {
            // Navigate to URL (e.g. delete endpoint)
            window.location.href = this._confirmUrl;
        }

        // Dispatch event for custom handling
        this.element.dispatchEvent(new CustomEvent('confirm-modal:confirmed', {
            bubbles: true,
            detail: { trigger: this._triggerElement }
        }));
    }

    /**
     * Cancel / close
     */
    cancel() {
        this._close();

        this.element.dispatchEvent(new CustomEvent('confirm-modal:cancelled', {
            bubbles: true,
            detail: { trigger: this._triggerElement }
        }));
    }

    /**
     * @param {KeyboardEvent} event
     */
    _handleKeydown(event) {
        if (event.key === 'Escape') {
            this.cancel();
            return;
        }

        // Focus trap between cancel and confirm buttons
        if (event.key === 'Tab') {
            const focusable = this.overlayTarget.querySelectorAll('button:not([disabled])');
            if (focusable.length < 2) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    _close() {
        this.overlayTarget.classList.remove('is-open');
        this.overlayTarget.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        this._removeKeyListener();

        // Return focus to trigger
        if (this._triggerElement) {
            this._triggerElement.focus();
            this._triggerElement = null;
        }

        this._confirmUrl = null;
    }

    _removeKeyListener() {
        if (this._onKeydown) {
            document.removeEventListener('keydown', this._onKeydown);
        }
    }
}
