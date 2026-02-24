import { Controller } from '@hotwired/stimulus';

/**
 * Image Modal Controller
 *
 * Displays images in a fullscreen modal overlay with zoom capabilities.
 *
 * Usage:
 *   <div data-controller="image-modal">
 *       <img src="..." data-action="click->image-modal#open" class="cursor-zoom-in">
 *
 *       <!-- Modal -->
 *       <div data-image-modal-target="modal" class="hidden">
 *           <img data-image-modal-target="image">
 *           <button data-action="image-modal#close">X</button>
 *       </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['modal', 'image'];

    connect() {
        // Listen for escape key
        this.escapeHandler = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.escapeHandler);
    }

    disconnect() {
        document.removeEventListener('keydown', this.escapeHandler);
    }

    open(event) {
        event.preventDefault();
        const src = event.currentTarget.src || event.currentTarget.dataset.imageModalSrc;

        if (src && this.hasImageTarget && this.hasModalTarget) {
            this.imageTarget.src = src;
            this.modalTarget.classList.remove('hidden');
            this.modalTarget.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
    }

    close(event) {
        if (event) {
            // Only close if clicking on backdrop, not on image
            if (event.target === this.imageTarget) {
                return;
            }
        }

        if (this.hasModalTarget) {
            this.modalTarget.classList.add('hidden');
            this.modalTarget.classList.remove('flex');
            document.body.style.overflow = '';
        }
    }

    handleEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
