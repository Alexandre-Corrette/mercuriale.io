import { Controller } from '@hotwired/stimulus';

const DISMISS_KEY = 'mercuriale_install_dismissed';
const DISMISS_DAYS = 14;

export default class extends Controller {
    static targets = ['androidPrompt', 'iosPrompt'];

    connect() {
        // Already installed as standalone PWA — skip
        if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) {
            return;
        }

        // Dismissed recently — skip
        if (this._isDismissedRecently()) {
            return;
        }

        this._deferredPrompt = null;

        if (this._isIOS()) {
            this._showIOSPrompt();
        } else {
            // Listen for Chrome/Android beforeinstallprompt
            this._beforeInstallHandler = (e) => {
                e.preventDefault();
                this._deferredPrompt = e;
                this._showAndroidPrompt();
            };
            window.addEventListener('beforeinstallprompt', this._beforeInstallHandler);
        }
    }

    disconnect() {
        if (this._beforeInstallHandler) {
            window.removeEventListener('beforeinstallprompt', this._beforeInstallHandler);
        }
    }

    async install() {
        if (!this._deferredPrompt) return;

        this._deferredPrompt.prompt();
        const { outcome } = await this._deferredPrompt.userChoice;

        if (outcome === 'accepted') {
            this._hideAll();
        }
        this._deferredPrompt = null;
    }

    dismiss() {
        localStorage.setItem(DISMISS_KEY, String(Date.now()));
        this._hideAll();
    }

    _showAndroidPrompt() {
        if (this.hasAndroidPromptTarget) {
            setTimeout(() => {
                this.androidPromptTarget.classList.add('install-prompt--visible');
            }, 2000);
        }
    }

    _showIOSPrompt() {
        if (this.hasIosPromptTarget) {
            setTimeout(() => {
                this.iosPromptTarget.classList.add('install-prompt--visible');
            }, 2000);
        }
    }

    _hideAll() {
        if (this.hasAndroidPromptTarget) {
            this.androidPromptTarget.classList.remove('install-prompt--visible');
        }
        if (this.hasIosPromptTarget) {
            this.iosPromptTarget.classList.remove('install-prompt--visible');
        }
    }

    _isDismissedRecently() {
        const dismissed = localStorage.getItem(DISMISS_KEY);
        if (!dismissed) return false;

        const daysSince = (Date.now() - parseInt(dismissed, 10)) / (1000 * 60 * 60 * 24);
        if (daysSince >= DISMISS_DAYS) {
            localStorage.removeItem(DISMISS_KEY);
            return false;
        }
        return true;
    }

    _isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }
}
