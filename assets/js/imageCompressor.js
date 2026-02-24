/**
 * Compresse une image avant stockage IndexedDB
 * Objectif : passer de ~5Mo à ~300-500Ko
 */
export async function compressImage(file, options = {}) {
    const {
        maxWidth = 1600,
        maxHeight = 1600,
        quality = 0.85,
        type = 'image/jpeg'
    } = options;

    // Validation du fichier
    if (!file || !file.type.startsWith('image/')) {
        throw new Error('Fichier non valide');
    }

    // Créer un bitmap depuis le fichier
    const bitmap = await createImageBitmap(file);

    // Calculer les nouvelles dimensions en gardant le ratio
    let width = bitmap.width;
    let height = bitmap.height;

    if (width > maxWidth) {
        height = (height * maxWidth) / width;
        width = maxWidth;
    }
    if (height > maxHeight) {
        width = (width * maxHeight) / height;
        height = maxHeight;
    }

    width = Math.round(width);
    height = Math.round(height);

    // Utiliser OffscreenCanvas si disponible (plus performant)
    let canvas;
    let ctx;

    if (typeof OffscreenCanvas !== 'undefined') {
        canvas = new OffscreenCanvas(width, height);
        ctx = canvas.getContext('2d');
    } else {
        // Fallback pour Safari < 16.4
        canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        ctx = canvas.getContext('2d');
    }

    // Dessiner l'image redimensionnée
    ctx.drawImage(bitmap, 0, 0, width, height);

    // Convertir en blob
    let blob;
    if (canvas.convertToBlob) {
        blob = await canvas.convertToBlob({ type, quality });
    } else {
        // Fallback pour canvas classique
        blob = await new Promise(resolve => {
            canvas.toBlob(resolve, type, quality);
        });
    }

    // Log pour debug
    console.log(`[ImageCompressor] ${file.name}: ${(file.size / 1024).toFixed(0)}Ko → ${(blob.size / 1024).toFixed(0)}Ko (${width}x${height})`);

    return {
        blob,
        originalSize: file.size,
        compressedSize: blob.size,
        width,
        height,
        compressionRatio: ((1 - blob.size / file.size) * 100).toFixed(1)
    };
}

/**
 * Compresse plusieurs images en parallèle
 */
export async function compressImages(files, options = {}) {
    const results = await Promise.all(
        Array.from(files).map(file => compressImage(file, options))
    );
    return results;
}
