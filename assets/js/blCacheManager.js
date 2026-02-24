import { getJwtToken } from './syncManager.js';
import {
    cacheBLs, getCachedBLs, getCachedBL, cacheBLImage,
    getCachedBLImage, getLastBLSyncTime, setLastBLSyncTime, evictOldBLImages
} from './db.js';
import { isOnline } from './networkProbe.js';

const MIN_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes
const IMAGE_PREFETCH_COUNT = 10;

let _refreshing = false;

function notifyUI() {
    window.dispatchEvent(new CustomEvent('bl-cache-updated'));
}

/**
 * Refresh the BL cache from the API (incremental sync).
 * @param {Object} options
 * @param {boolean} options.force - Skip cooldown check
 * @param {number|null} options.etablissementId - Filter by etablissement
 */
export async function refreshBLCache({ force = false, etablissementId = null } = {}) {
    if (_refreshing) return;
    if (!await isOnline()) return;

    // Skip if synced recently (unless force)
    if (!force) {
        const lastSync = await getLastBLSyncTime();
        if (lastSync && (Date.now() - new Date(lastSync).getTime()) < MIN_REFRESH_INTERVAL) {
            return;
        }
    }

    _refreshing = true;

    try {
        const token = await getJwtToken();
        const lastSync = await getLastBLSyncTime();

        const params = new URLSearchParams();
        if (etablissementId) params.set('etablissementId', String(etablissementId));
        if (lastSync && !force) params.set('since', lastSync);
        params.set('limit', '200');

        const url = '/api/bons-livraison' + (params.toString() ? '?' + params.toString() : '');

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Authorization': `Bearer ${token}` },
        });

        if (!response.ok) {
            throw new Error(`API error: ${response.status}`);
        }

        const result = await response.json();

        if (result.success && result.data.length > 0) {
            await cacheBLs(result.data);

            // Prefetch images for most recent BLs in background
            prefetchImages(result.data.slice(0, IMAGE_PREFETCH_COUNT), token);
        }

        await setLastBLSyncTime(new Date().toISOString());
        notifyUI();

    } catch (error) {
        console.error('[BLCacheManager] Erreur refresh:', error.message);
    } finally {
        _refreshing = false;
    }
}

/**
 * Prefetch images for a list of BLs (non-blocking).
 */
async function prefetchImages(bls, token) {
    for (const bl of bls) {
        if (!bl.hasImage) continue;

        try {
            // Skip if already cached
            const existing = await getCachedBLImage(bl.id);
            if (existing) continue;

            const response = await fetch(`/api/bons-livraison/${bl.id}/image`, {
                credentials: 'same-origin',
                headers: { 'Authorization': `Bearer ${token}` },
            });

            if (response.ok) {
                const blob = await response.blob();
                await cacheBLImage(bl.id, blob);
            }
        } catch (error) {
            // Non-critical: image prefetch failure is not a problem
            console.warn('[BLCacheManager] Prefetch image échoué pour BL', bl.id);
        }
    }

    // Evict old images to stay under limit
    await evictOldBLImages(50);
}

/**
 * Get the list of cached BLs, refreshing first if online.
 */
export async function getBLList(etablissementId = null) {
    await refreshBLCache({ etablissementId });
    return getCachedBLs(etablissementId);
}

/**
 * Get a single cached BL by id.
 */
export async function getBLDetail(blId) {
    return getCachedBL(blId);
}

/**
 * Get BL image blob (cache-first: IndexedDB → API fetch → store).
 */
export async function getBLImage(blId) {
    // Try cache first
    const cached = await getCachedBLImage(blId);
    if (cached) return cached;

    // Try API if online
    if (!await isOnline()) return null;

    try {
        const token = await getJwtToken();
        const response = await fetch(`/api/bons-livraison/${blId}/image`, {
            credentials: 'same-origin',
            headers: { 'Authorization': `Bearer ${token}` },
        });

        if (!response.ok) return null;

        const blob = await response.blob();
        await cacheBLImage(blId, blob);
        await evictOldBLImages(50);

        return blob;
    } catch (error) {
        console.warn('[BLCacheManager] Erreur chargement image BL', blId);
        return null;
    }
}
