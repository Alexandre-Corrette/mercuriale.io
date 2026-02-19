/**
 * Détecte si le navigateur supporte l'encodage WebP via canvas.
 * Résultat mis en cache après le premier appel.
 */
let _webpSupported = null;
function supportsWebP() {
    if (_webpSupported !== null) return _webpSupported;

    try {
        const c = document.createElement('canvas');
        c.width = 1;
        c.height = 1;
        _webpSupported = c.toDataURL('image/webp').startsWith('data:image/webp');
    } catch {
        _webpSupported = false;
    }

    return _webpSupported;
}

/**
 * Compresse une image avant stockage IndexedDB
 * Utilise WebP si supporté (30-40% plus léger), JPEG en fallback
 * Objectif : passer de ~5Mo à ~200-400Ko
 */
export async function compressImage(file, options = {}) {
    const outputType = supportsWebP() ? 'image/webp' : 'image/jpeg';
    const {
        maxWidth = 1400,
        maxHeight = 1400,
        quality = 0.82,
        maxSizeBytes = 3 * 1024 * 1024,
        type = outputType
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

    if (width > maxWidth || height > maxHeight) {
        const ratio = Math.min(maxWidth / width, maxHeight / height);
        width = Math.round(width * ratio);
        height = Math.round(height * ratio);
    }

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

    // Boucle de compression dégressive si trop gros
    let currentQuality = quality;
    let blob;

    do {
        blob = canvas.convertToBlob
            ? await canvas.convertToBlob({ type, quality: currentQuality })
            : await new Promise(resolve => canvas.toBlob(resolve, type, currentQuality));

        if (blob.size <= maxSizeBytes) break;
        currentQuality -= 0.1;
    } while (currentQuality > 0.3);

    // Log pour debug
    const format = type === 'image/webp' ? 'WebP' : 'JPEG';
    console.log(`[ImageCompressor] ${file.name}: ${(file.size / 1024).toFixed(0)}Ko → ${(blob.size / 1024).toFixed(0)}Ko (${width}x${height}, ${format}, q=${currentQuality.toFixed(1)})`);

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
