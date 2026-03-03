import { Controller } from '@hotwired/stimulus';

/**
 * Toast Notification Controller
 *
 * Listens for 'toast:show' custom events on document and displays
 * slide-in notifications. Auto-dismisses after configurable duration.
 *
 * Usage:
 *   document.dispatchEvent(new CustomEvent('toast:show', {
 *       detail: { message: 'BL validé', variant: 'success', duration: 5000 }
 *   }));
 *
 * Variants: success, danger, warning, info
 */
export default class extends Controller {
    static values = {
        duration: { type: Number, default: 5000 }
    };

    /** @type {Map<HTMLElement, number>} */
    _timers = new Map();

    connect() {
        this._onToastShow = this._handleToastShow.bind(this);
        document.addEventListener('toast:show', this._onToastShow);
    }

    disconnect() {
        document.removeEventListener('toast:show', this._onToastShow);
        this._timers.forEach((timer) => clearTimeout(timer));
        this._timers.clear();
    }

    /**
     * @param {CustomEvent} event
     */
    _handleToastShow(event) {
        const { message, variant = 'info', duration } = event.detail || {};
        if (!message) return;
        this.show(message, variant, duration);
    }

    /**
     * @param {string} message
     * @param {string} variant - success|danger|warning|info
     * @param {number} [duration]
     */
    show(message, variant = 'info', duration) {
        const toast = this._createToastElement(message, variant);
        this.element.appendChild(toast);

        // Trigger slide-in on next frame
        requestAnimationFrame(() => {
            toast.classList.add('is-visible');
        });

        // Auto-dismiss
        const ms = duration || this.durationValue;
        const timer = setTimeout(() => this._dismiss(toast), ms);
        this._timers.set(toast, timer);
    }

    /**
     * Dismiss action (click on close button)
     * @param {Event} event
     */
    dismiss(event) {
        const toast = event.currentTarget.closest('.m-toast');
        if (toast) this._dismiss(toast);
    }

    /**
     * @param {HTMLElement} toast
     */
    _dismiss(toast) {
        const timer = this._timers.get(toast);
        if (timer) {
            clearTimeout(timer);
            this._timers.delete(toast);
        }

        toast.classList.remove('is-visible');
        toast.classList.add('is-exiting');

        toast.addEventListener('transitionend', () => {
            toast.remove();
        }, { once: true });

        // Fallback removal if transition doesn't fire
        setTimeout(() => {
            if (toast.parentNode) toast.remove();
        }, 500);
    }

    /**
     * @param {string} message
     * @param {string} variant
     * @returns {HTMLElement}
     */
    _createToastElement(message, variant) {
        const iconMap = {
            success: 'fa-check-circle',
            danger: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const toast = document.createElement('div');
        toast.className = `m-toast m-toast--${variant}`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <i class="fas ${iconMap[variant] || iconMap.info} m-toast__icon" aria-hidden="true"></i>
            <span class="m-toast__message">${this._escapeHtml(message)}</span>
            <button class="m-toast__close" data-action="toast#dismiss" aria-label="Fermer">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        `;

        return toast;
    }

    /**
     * @param {string} str
     * @returns {string}
     */
    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}