import { Controller } from '@hotwired/stimulus';

const VISIT_COUNT_KEY = 'mercuriale_push_visits';
const DISMISS_KEY = 'mercuriale_push_dismissed';
const DISMISS_DAYS = 30;
const VISITS_BEFORE_PROMPT = 3;

export default class extends Controller {
    static values = { vapidKey: String };
    static targets = ['prompt'];

    connect() {
        // Push API not supported
        if (!('PushManager' in window) || !('serviceWorker' in navigator)) {
            return;
        }

        const permission = Notification.permission;

        if (permission === 'granted') {
            this.silentSubscribe();
        } else if (permission === 'default') {
            this.trackVisitAndMaybePrompt();
        }
        // If 'denied', do nothing
    }

    async silentSubscribe() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const existing = await registration.pushManager.getSubscription();

            if (existing) {
                // Already subscribed, re-send to server to keep in sync
                await this.sendSubscriptionToServer(existing);
                return;
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.vapidKeyValue),
            });

            await this.sendSubscriptionToServer(subscription);
        } catch (e) {
            console.warn('[Push] Silent subscribe failed:', e);
        }
    }

    trackVisitAndMaybePrompt() {
        // Check if user dismissed recently
        const dismissed = localStorage.getItem(DISMISS_KEY);
        if (dismissed) {
            const dismissedAt = parseInt(dismissed, 10);
            const daysSince = (Date.now() - dismissedAt) / (1000 * 60 * 60 * 24);
            if (daysSince < DISMISS_DAYS) {
                return;
            }
            localStorage.removeItem(DISMISS_KEY);
        }

        // Increment visit counter
        const visits = parseInt(localStorage.getItem(VISIT_COUNT_KEY) || '0', 10) + 1;
        localStorage.setItem(VISIT_COUNT_KEY, String(visits));

        if (visits >= VISITS_BEFORE_PROMPT && this.hasPromptTarget) {
            // Show prompt with slight delay for UX
            setTimeout(() => {
                this.promptTarget.classList.add('push-prompt--visible');
            }, 1500);
        }
    }

    async accept() {
        this.hidePrompt();
        localStorage.removeItem(VISIT_COUNT_KEY);

        try {
            const permission = await Notification.requestPermission();

            if (permission === 'granted') {
                await this.silentSubscribe();
            }
        } catch (e) {
            console.error('[Push] Permission request failed:', e);
        }
    }

    dismiss() {
        this.hidePrompt();
        localStorage.setItem(DISMISS_KEY, String(Date.now()));
    }

    hidePrompt() {
        if (this.hasPromptTarget) {
            this.promptTarget.classList.remove('push-prompt--visible');
        }
    }

    async sendSubscriptionToServer(subscription) {
        const data = subscription.toJSON();

        try {
            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: data.endpoint,
                    keys: {
                        p256dh: data.keys.p256dh,
                        auth: data.keys.auth,
                    },
                }),
            });

            if (!response.ok) {
                console.warn('[Push] Server subscription failed:', response.status);
            }
        } catch (e) {
            console.warn('[Push] Could not send subscription to server:', e);
        }
    }

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }
}
