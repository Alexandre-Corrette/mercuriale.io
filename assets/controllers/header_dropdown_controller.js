import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'menu'];
    static values = { class: { type: String, default: 'm-header__restaurant-dropdown--open' } };

    connect() {
        this._onOutsideClick = this._close.bind(this);
        this._onEscape = this._handleEscape.bind(this);
        document.addEventListener('click', this._onOutsideClick);
        document.addEventListener('keydown', this._onEscape);
    }

    disconnect() {
        document.removeEventListener('click', this._onOutsideClick);
        document.removeEventListener('keydown', this._onEscape);
    }

    toggle(event) {
        event.stopPropagation();
        var isOpen = this.element.classList.toggle(this.classValue);
        this.toggleTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    _close() {
        this.element.classList.remove(this.classValue);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', 'false');
        }
    }

    _handleEscape(event) {
        if (event.key === 'Escape') {
            this._close();
        }
    }
}
