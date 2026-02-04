import { Controller } from '@hotwired/stimulus';

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
        this.dropZoneTarget.classList.add('border-coral', 'bg-coral/10', 'scale-[1.01]');
        this.dropZoneTarget.classList.remove('border-gray-300', 'bg-gray-50');
    }

    unhighlightDropZone() {
        this.dropZoneTarget.classList.remove('border-coral', 'bg-coral/10', 'scale-[1.01]');
        this.dropZoneTarget.classList.add('border-gray-300', 'bg-gray-50');
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
                this.previewTarget.classList.remove('hidden');
                if (this.hasDropZoneTarget) {
                    if (this.modeValue === 'single') {
                        this.dropZoneTarget.classList.add('hidden');
                    } else {
                        this.dropZoneTarget.classList.add('border-green-500', 'border-solid', 'bg-green-50/50');
                        this.dropZoneTarget.classList.remove('border-dashed', 'border-gray-300');
                    }
                }
            } else {
                this.previewTarget.classList.add('hidden');
                if (this.hasDropZoneTarget) {
                    this.dropZoneTarget.classList.remove('hidden', 'border-green-500', 'border-solid', 'bg-green-50/50');
                    this.dropZoneTarget.classList.add('border-dashed', 'border-gray-300');
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
        fileItem.className = 'flex items-center gap-4 p-3 bg-white rounded-xl shadow-sm';

        // Preview thumbnail
        const preview = document.createElement('div');
        preview.className = 'w-14 h-14 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0 flex items-center justify-center';

        if (item.file.type === 'application/pdf') {
            preview.innerHTML = '<i class="fas fa-file-pdf text-2xl text-red-500"></i>';
        } else if (item.file.name.endsWith('.xlsx') || item.file.name.endsWith('.xls')) {
            preview.innerHTML = '<i class="fas fa-file-excel text-2xl text-green-600"></i>';
        } else if (item.file.name.endsWith('.csv')) {
            preview.innerHTML = '<i class="fas fa-file-csv text-2xl text-blue-500"></i>';
        } else if (item.file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.className = 'w-full h-full object-cover';
            const reader = new FileReader();
            reader.onload = (e) => img.src = e.target.result;
            reader.readAsDataURL(item.file);
            preview.appendChild(img);
        } else {
            preview.innerHTML = '<i class="fas fa-file text-2xl text-gray-400"></i>';
        }

        // File info
        const info = document.createElement('div');
        info.className = 'flex-1 min-w-0';

        const name = document.createElement('div');
        name.className = 'font-semibold text-gray-900 text-sm truncate';
        name.textContent = item.file.name;

        const size = document.createElement('div');
        size.className = 'text-xs text-gray-500';
        size.textContent = this.formatFileSize(item.file.size);

        info.appendChild(name);
        info.appendChild(size);

        // Status
        const status = document.createElement('div');
        status.className = 'flex items-center gap-1 text-xs flex-shrink-0';

        if (item.valid) {
            status.className += ' text-green-600';
            status.innerHTML = '<i class="fas fa-check-circle"></i><span>OK</span>';
        } else {
            status.className += ' text-red-600';
            status.innerHTML = `<i class="fas fa-times-circle"></i><span>${item.error}</span>`;
        }

        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'w-8 h-8 flex items-center justify-center bg-gray-100 rounded-lg text-gray-500 hover:bg-red-100 hover:text-red-600 transition-all flex-shrink-0';
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

    submit(event) {
        const validFiles = this.selectedFiles.filter(f => f.valid);

        if (validFiles.length === 0) {
            event.preventDefault();
            return;
        }

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
            this.submitTarget.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${this.loadingTextValue}`;
        }
    }
}
