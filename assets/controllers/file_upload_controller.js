import { Controller } from '@hotwired/stimulus';
import { addPendingBL, addPendingPhoto, countPendingBLs } from '../js/db.js';
import { compressImage } from '../js/imageCompressor.js';

/**
 * File Upload Controller
 *
 * Handles drag & drop file uploads with preview, validation and multi-file support.
 *
 * Usage:
 *   <div data-controller="file-upload"
 *        data-file-upload-max-size-value="20971520"
 *        data-file-upload-max-files-value="10"
 *        data-file-upload-allowed-types-value='["image/jpeg","image/png","application/pdf"]'>
 *       <div data-file-upload-target="dropZone">...</div>
 *       <input type="file" data-file-upload-target="input" multiple>
 *       <div data-file-upload-target="preview">...</div>
 *       <div data-file-upload-target="filesList">...</div>
 *       <span data-file-upload-target="filesCount">0</span>
 *       <button data-file-upload-target="submit" disabled>Submit</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['dropZone', 'input', 'preview', 'filesList', 'filesCount', 'submit', 'form'];

    static values = {
        maxSize: { type: Number, default: 20 * 1024 * 1024 }, // 20MB
        maxFiles: { type: Number, default: 10 },
        allowedTypes: { type: Array, default: ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'application/pdf'] },
        allowedExtensions: { type: Array, default: ['csv', 'xlsx'] },
        mode: { type: String, default: 'multi' }, // 'multi' or 'single'
        submitText: { type: String, default: 'Analyser' },
        loadingText: { type: String, default: 'Analyse en cours...' }
    };

    connect() {
        this.selectedFiles = [];
        this.setupDropZone();
        this.setupFileInput();
    }

    setupDropZone() {
        if (!this.hasDropZoneTarget) return;

        // Click to browse
        this.dropZoneTarget.addEventListener('click', () => this.inputTarget.click());

        // Drag and drop events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            this.dropZoneTarget.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            this.dropZoneTarget.addEventListener(eventName, () => this.highlightDropZone());
        });

        ['dragleave', 'drop'].forEach(eventName => {
            this.dropZoneTarget.addEventListener(eventName, () => this.unhighlightDropZone());
        });

        this.dropZoneTarget.addEventListener('drop', (e) => {
            const files = Array.from(e.dataTransfer.files);
            this.handleFiles(files);
        });
    }

    setupFileInput() {
        if (!this.hasInputTarget) return;

        this.inputTarget.addEventListener('change', () => {
            const files = Array.from(this.inputTarget.files);
            this.handleFiles(files);
        });
    }

    highlightDropZone() {
        this.dropZoneTarget.classList.add('mi-dropzone--active');
    }

    unhighlightDropZone() {
        this.dropZoneTarget.classList.remove('mi-dropzone--active');
    }

    browse(event) {
        event.preventDefault();
        event.stopPropagation();
        this.inputTarget.click();
    }

    handleFiles(files) {
        if (this.modeValue === 'single') {
            // Single file mode
            const file = files[0];
            if (file) {
                const validation = this.validateFile(file);
                if (validation.valid) {
                    this.selectedFiles = [{ file, valid: true, error: null }];
                    this.updateFileInput();
                    this.updateUI();
                } else {
                    alert(validation.error);
                }
            }
        } else {
            // Multi file mode
            const remainingSlots = this.maxFilesValue - this.selectedFiles.length;
            const filesToAdd = files.slice(0, remainingSlots);

            filesToAdd.forEach(file => {
                const validation = this.validateFile(file);
                this.selectedFiles.push({
                    file,
                    valid: validation.valid,
                    error: validation.error
                });
            });

            if (files.length > remainingSlots) {
                alert(`Maximum ${this.maxFilesValue} fichiers. ${files.length - remainingSlots} fichier(s) ignoré(s).`);
            }

            this.updateFileInput();
            this.updateUI();
        }
    }

    validateFile(file) {
        // Check by allowed types (MIME)
        if (this.allowedTypesValue.length > 0) {
            const isTypeAllowed = this.allowedTypesValue.includes(file.type) ||
                file.name.toLowerCase().endsWith('.heic');

            if (!isTypeAllowed) {
                // Check by extension as fallback
                const ext = file.name.split('.').pop().toLowerCase();
                if (!this.allowedExtensionsValue.includes(ext)) {
                    return { valid: false, error: 'Format non supporté' };
                }
            }
        }

        // Check by extension only
        if (this.allowedExtensionsValue.length > 0 && this.allowedTypesValue.length === 0) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!this.allowedExtensionsValue.includes(ext)) {
                return { valid: false, error: 'Format non supporté. Utilisez un fichier ' + this.allowedExtensionsValue.join(' ou ').toUpperCase() + '.' };
            }
        }

        // Check size
        if (file.size > this.maxSizeValue) {
            const maxMB = Math.round(this.maxSizeValue / (1024 * 1024));
            return { valid: false, error: `Fichier trop volumineux (max ${maxMB} Mo)` };
        }

        return { valid: true, error: null };
    }

    updateFileInput() {
        const dataTransfer = new DataTransfer();
        this.selectedFiles.filter(f => f.valid).forEach(f => dataTransfer.items.add(f.file));
        this.inputTarget.files = dataTransfer.files;
    }

    updateUI() {
        const validCount = this.selectedFiles.filter(f => f.valid).length;

        // Update count
        if (this.hasFilesCountTarget) {
            this.filesCountTarget.textContent = validCount;
        }

        // Show/hide preview section
        if (this.hasPreviewTarget) {
            if (this.selectedFiles.length > 0) {
                this.previewTarget.classList.add('mi-file-preview--visible');
                if (this.hasDropZoneTarget) {
                    if (this.modeValue === 'single') {
                        this.dropZoneTarget.classList.add('hidden');
                    } else {
                        this.dropZoneTarget.classList.add('mi-dropzone--has-files');
                    }
                }
            } else {
                this.previewTarget.classList.remove('mi-file-preview--visible');
                if (this.hasDropZoneTarget) {
                    this.dropZoneTarget.classList.remove('hidden', 'mi-dropzone--has-files');
                }
            }
        }

        // Enable/disable submit
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = validCount === 0;
        }

        // Render file list
        this.renderFileList();
    }

    renderFileList() {
        if (!this.hasFilesListTarget) return;

        this.filesListTarget.innerHTML = '';

        this.selectedFiles.forEach((item, index) => {
            const fileItem = this.createFileItem(item, index);
            this.filesListTarget.appendChild(fileItem);
        });
    }

    createFileItem(item, index) {
        const fileItem = document.createElement('div');
        fileItem.className = 'mi-file-item';

        // Preview thumbnail
        const preview = document.createElement('div');
        preview.className = 'mi-file-item__thumb';

        if (item.file.type === 'application/pdf') {
            preview.innerHTML = '<i class="fas fa-file-pdf mi-file-item__icon mi-file-item__icon--pdf"></i>';
        } else if (item.file.name.endsWith('.xlsx') || item.file.name.endsWith('.xls')) {
            preview.innerHTML = '<i class="fas fa-file-excel mi-file-item__icon mi-file-item__icon--excel"></i>';
        } else if (item.file.name.endsWith('.csv')) {
            preview.innerHTML = '<i class="fas fa-file-csv mi-file-item__icon mi-file-item__icon--csv"></i>';
        } else if (item.file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.className = 'mi-file-item__img';
            const reader = new FileReader();
            reader.onload = (e) => img.src = e.target.result;
            reader.readAsDataURL(item.file);
            preview.appendChild(img);
        } else {
            preview.innerHTML = '<i class="fas fa-file mi-file-item__icon mi-file-item__icon--default"></i>';
        }

        // File info
        const info = document.createElement('div');
        info.className = 'mi-file-item__info';

        const name = document.createElement('div');
        name.className = 'mi-file-item__name';
        name.textContent = item.file.name;

        const size = document.createElement('div');
        size.className = 'mi-file-item__size';
        size.textContent = this.formatFileSize(item.file.size);

        info.appendChild(name);
        info.appendChild(size);

        // Status
        const status = document.createElement('div');
        status.className = 'mi-file-item__status';

        if (item.valid) {
            status.classList.add('mi-file-item__status--ok');
            status.innerHTML = '<i class="fas fa-check-circle"></i><span>OK</span>';
        } else {
            status.classList.add('mi-file-item__status--error');
            status.innerHTML = `<i class="fas fa-times-circle"></i><span>${item.error}</span>`;
        }

        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'mi-file-item__remove';
        removeBtn.innerHTML = '<i class="fas fa-times"></i>';
        removeBtn.dataset.action = 'file-upload#removeFile';
        removeBtn.dataset.fileUploadIndexParam = index;

        fileItem.appendChild(preview);
        fileItem.appendChild(info);
        fileItem.appendChild(status);
        fileItem.appendChild(removeBtn);

        return fileItem;
    }

    removeFile(event) {
        const index = parseInt(event.params.index, 10);
        this.selectedFiles.splice(index, 1);
        this.updateFileInput();
        this.updateUI();
    }

    clearAll() {
        this.selectedFiles = [];
        this.updateFileInput();
        this.updateUI();
    }

    formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / (1024 * 1024)).toFixed(2) + ' Mo';
    }

    isOnline() {
        return navigator.onLine;
    }

    async submit(event) {
        const validFiles = this.selectedFiles.filter(f => f.valid);

        if (validFiles.length === 0) {
            event.preventDefault();
            return;
        }

        // Si online, comportement normal (submit du form)
        if (this.isOnline()) {
            if (this.hasSubmitTarget) {
                this.submitTarget.disabled = true;
                this.submitTarget.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${this.loadingTextValue}`;
            }
            return; // Laisse le form se soumettre normalement
        }

        // Mode OFFLINE : stocker localement
        event.preventDefault();

        const etablissementSelect = document.querySelector('[name*="etablissement"]');
        const etablissementId = etablissementSelect?.value;
        const etablissementNom = etablissementSelect?.options[etablissementSelect.selectedIndex]?.text;

        if (!etablissementId) {
            this.showError('Veuillez sélectionner un établissement');
            return;
        }

        this.setLoading(true, 'Enregistrement local...');

        try {
            for (const item of validFiles) {
                // Compresser l'image
                const compressed = await compressImage(item.file);

                // Créer l'entrée BL
                const pendingBLId = await addPendingBL({
                    etablissementId: parseInt(etablissementId),
                    etablissementNom,
                    fournisseurId: null, // Sera déterminé après OCR
                    fournisseurNom: null
                });

                // Stocker la photo compressée
                await addPendingPhoto(pendingBLId, compressed.blob, item.file.name);
            }

            // Afficher confirmation
            this.showOfflineSuccess(validFiles.length);

            // Reset du formulaire
            this.clearAll();

            // Mettre à jour le badge si présent
            this.updatePendingBadge();

        } catch (error) {
            console.error('[Offline] Erreur stockage:', error);
            this.showError('Erreur lors de l\'enregistrement local : ' + error.message);
        } finally {
            this.setLoading(false);
        }
    }

    setLoading(loading, text = null) {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = loading;
            if (loading && text) {
                this.submitTarget.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${text}`;
            } else if (!loading) {
                this.submitTarget.innerHTML = `<i class="fas fa-search"></i> ${this.submitTextValue}`;
            }
        }
    }

    showError(message) {
        const alert = document.createElement('div');
        alert.className = 'mi-alert mi-alert--danger';
        alert.innerHTML = `
            <i class="fas fa-exclamation-circle"></i>
            <span>${message}</span>
            <button type="button" class="mi-alert__close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        this.element.parentNode.insertBefore(alert, this.element);
        setTimeout(() => alert.remove(), 10000);
    }

    showOfflineSuccess(count) {
        const message = count === 1
            ? 'Photo enregistrée localement — sera envoyée dès que le réseau sera disponible'
            : `${count} photos enregistrées localement — seront envoyées dès que le réseau sera disponible`;

        const alert = document.createElement('div');
        alert.className = 'mi-alert mi-alert--info';
        alert.innerHTML = `
            <i class="fas fa-cloud-upload-alt"></i>
            <span>${message}</span>
            <a href="/app/pending" class="mi-alert__link">
                Voir les BL en attente →
            </a>
        `;

        this.element.parentNode.insertBefore(alert, this.element);
        setTimeout(() => alert.remove(), 10000);
    }

    async updatePendingBadge() {
        try {
            const count = await countPendingBLs();
            document.querySelectorAll('[data-pending-badge]').forEach(badge => {
                badge.textContent = count;
                badge.classList.toggle('hidden', count === 0);
            });
        } catch (e) {
            console.error('[FileUpload] Erreur mise à jour badge:', e);
        }
    }
}
