import { Controller } from '@hotwired/stimulus';

/**
 * Session Flash Controller
 *
 * Lit un message dans sessionStorage (flash_warning, flash_info, flash_success, flash_danger)
 * et l'affiche en bannière puis le supprime. Utilisé pour des messages déposés par du JS
 * dans un autre contexte (ex. polling extraction qui détecte un BL doublon supprimé par
 * le worker, et veut informer le user après redirect).
 *
 * Usage minimal :
 *   <div data-controller="session-flash"></div>
 */
export default class extends Controller {
    static values = {
        keys: { type: Array, default: ['flash_warning', 'flash_info', 'flash_success', 'flash_danger'] },
    };

    static levelToVariant = {
        flash_warning: 'warning',
        flash_info: 'info',
        flash_success: 'success',
        flash_danger: 'danger',
    };

    static levelToIcon = {
        flash_warning: 'fa-triangle-exclamation',
        flash_info: 'fa-circle-info',
        flash_success: 'fa-circle-check',
        flash_danger: 'fa-circle-exclamation',
    };

    connect() {
        for (const key of this.keysValue) {
            const message = sessionStorage.getItem(key);
            if (!message) continue;

            sessionStorage.removeItem(key);
            this.renderBanner(key, message);
        }
    }

    renderBanner(key, message) {
        const variant = this.constructor.levelToVariant[key] || 'info';
        const icon = this.constructor.levelToIcon[key] || 'fa-circle-info';

        const banner = document.createElement('div');
        banner.className = `m-flash m-flash--${variant} m-flash--with-icon`;
        banner.setAttribute('role', 'alert');
        banner.innerHTML = `
            <i class="fas ${icon} m-flash__icon" aria-hidden="true"></i>
            <span class="m-flash__text">${this.escapeHtml(message)}</span>
            <button type="button" class="m-flash__close" aria-label="Fermer">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        `;

        banner.querySelector('.m-flash__close').addEventListener('click', () => banner.remove());

        this.element.appendChild(banner);
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }
}
