import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modal'];

    open() {
        this.modalTarget.hidden = false;
    }

    close() {
        this.modalTarget.hidden = true;
    }
}
