import { db, BL_STATUS, updateBLStatus, incrementRetryCount, getBLsReadyToSync, cleanupSyncedBLs } from './db.js';
import { isOnline } from './networkProbe.js';

// JWT token cache
let _cachedToken = null;
let _tokenExpiresAt = 0;

// Sync lock
let _syncing = false;

// Backoff delays in ms: 2s, 8s, 32s
const BACKOFF_DELAYS = [2000, 8000, 32000];
const MAX_RETRIES = 3;

/**
 * Get a JWT access token, refreshing if needed.
 * Uses the HttpOnly refresh_token cookie (sent automatically for same-origin).
 */
export async function getJwtToken() {
    // Return cached token if still valid (with 60s safety margin)
    if (_cachedToken && Date.now() < _tokenExpiresAt - 60000) {
        return _cachedToken;
    }

    const response = await fetch('/api/token/refresh', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
    });

    if (!response.ok) {
        if (response.status === 401) {
            window.dispatchEvent(new CustomEvent('auth-session-lost'));
        }
        throw new Error(`Token refresh failed: ${response.status}`);
    }

    const data = await response.json();
    _cachedToken = data.token;
    // Cache for 14 minutes (token TTL is 15min)
    _tokenExpiresAt = Date.now() + 14 * 60 * 1000;

    return _cachedToken;
}

/**
 * Dispatch a custom event so UI components can update badges/lists.
 */
function notifyUI() {
    window.dispatchEvent(new CustomEvent('sync-status-changed'));
}

/**
 * Upload a single BL with its photos to the server.
 * @param {number} blId - The IndexedDB id of the BL to sync
 * @returns {Promise<boolean>} true if synced, false if failed
 */
export async function syncOne(blId) {
    const bl = await db.pendingBL.get(blId);
    if (!bl) return false;

    // Skip BLs that are already synced or currently uploading
    if (bl.status === BL_STATUS.SYNCED || bl.status === BL_STATUS.UPLOADING) {
        return false;
    }

    // Skip if max retries exceeded
    if ((bl.retryCount || 0) >= MAX_RETRIES) {
        return false;
    }

    try {
        await updateBLStatus(blId, BL_STATUS.UPLOADING);
        notifyUI();

        const token = await getJwtToken();
        const photos = await db.pendingPhotos.where('pendingBLId').equals(blId).toArray();

        if (photos.length === 0) {
            console.warn('[SyncManager] BL sans photo, marqué FAILED:', blId);
            await updateBLStatus(blId, BL_STATUS.FAILED);
            notifyUI();
            return false;
        }

        // Upload each photo as a separate BL (the server creates one BL per file)
        for (const photo of photos) {
            const formData = new FormData();
            formData.append('file', photo.blob, photo.originalName || 'photo.jpg');
            formData.append('etablissementId', String(bl.etablissementId));

            const response = await fetch('/api/delivery-notes', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Authorization': `Bearer ${token}`,
                },
                body: formData,
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `Upload failed: ${response.status}`);
            }
        }

        // All photos uploaded successfully
        await updateBLStatus(blId, BL_STATUS.SYNCED);
        notifyUI();
        console.info('[SyncManager] BL synchronisé:', blId);
        return true;

    } catch (error) {
        console.error('[SyncManager] Échec sync BL:', blId, error.message);

        await incrementRetryCount(blId);
        await updateBLStatus(blId, BL_STATUS.FAILED);
        notifyUI();

        // Schedule retry with exponential backoff if under max retries
        const bl2 = await db.pendingBL.get(blId);
        const retryCount = bl2?.retryCount || 0;
        if (retryCount < MAX_RETRIES) {
            const delay = BACKOFF_DELAYS[retryCount - 1] || BACKOFF_DELAYS[BACKOFF_DELAYS.length - 1];
            console.info(`[SyncManager] Retry #${retryCount} pour BL ${blId} dans ${delay / 1000}s`);
            setTimeout(() => syncOne(blId), delay);
        }

        return false;
    }
}

/**
 * Sync all pending BLs sequentially.
 * Uses a lock to prevent concurrent sync runs.
 */
export async function syncAll() {
    if (_syncing) {
        console.info('[SyncManager] Sync déjà en cours, ignoré');
        return;
    }

    if (!await isOnline()) {
        console.info('[SyncManager] Hors ligne, sync reportée');
        return;
    }

    _syncing = true;
    console.info('[SyncManager] Début synchronisation...');

    try {
        const bls = await getBLsReadyToSync();

        if (bls.length === 0) {
            console.info('[SyncManager] Aucun BL à synchroniser');
            return;
        }

        console.info(`[SyncManager] ${bls.length} BL(s) à synchroniser`);

        for (const bl of bls) {
            // Stop if we went offline during sync
            if (!await isOnline()) {
                console.info('[SyncManager] Connexion perdue, arrêt de la sync');
                break;
            }

            await syncOne(bl.id);
        }

        // Cleanup old synced BLs
        const cleaned = await cleanupSyncedBLs();
        if (cleaned > 0) {
            console.info(`[SyncManager] ${cleaned} ancien(s) BL(s) nettoyé(s)`);
        }

    } catch (error) {
        console.error('[SyncManager] Erreur globale sync:', error);
    } finally {
        _syncing = false;
        notifyUI();
    }
}
