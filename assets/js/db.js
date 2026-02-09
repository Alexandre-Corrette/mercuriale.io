import Dexie from 'dexie';

export const db = new Dexie('mercurialeDB');

db.version(1).stores({
    // BL en attente de synchronisation
    pendingBL: '++id, etablissementId, fournisseurId, status, createdAt',
    // Photos associées (blob séparé pour performance)
    pendingPhotos: '++id, pendingBLId',
    // Cache des référentiels pour mode offline
    referentiels: 'key, updatedAt'
});

db.version(2).stores({
    pendingBL: '++id, etablissementId, fournisseurId, status, createdAt',
    pendingPhotos: '++id, pendingBLId',
    referentiels: 'key, updatedAt',
    // Cache des BL validés pour consultation offline (id = server BL id, not auto-increment)
    cachedBLs: 'id, etablissementId, statut, validatedAt, cachedAt',
    // Images des BL cachés (1:1 avec BL, stocke le blob)
    cachedBLImages: 'blId, cachedAt, lastAccessedAt'
});

// Statuts possibles
export const BL_STATUS = {
    PENDING: 'PENDING',       // En attente de réseau
    UPLOADING: 'UPLOADING',   // Envoi en cours
    UPLOADED: 'UPLOADED',     // Photo envoyée, OCR en attente
    FAILED: 'FAILED',         // Échec après X tentatives
    SYNCED: 'SYNCED'          // Terminé, peut être nettoyé
};

// Helper : ajouter un BL en attente
export async function addPendingBL(data) {
    const id = await db.pendingBL.add({
        etablissementId: data.etablissementId,
        fournisseurId: data.fournisseurId,
        fournisseurNom: data.fournisseurNom || null,
        etablissementNom: data.etablissementNom || null,
        status: BL_STATUS.PENDING,
        retryCount: 0,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString()
    });
    return id;
}

// Helper : ajouter une photo pour un BL
export async function addPendingPhoto(pendingBLId, blob, originalName) {
    // Validation taille max (5 Mo après compression)
    if (blob.size > 5 * 1024 * 1024) {
        throw new Error('Photo trop volumineuse (max 5 Mo)');
    }
    // Validation type MIME
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(blob.type)) {
        throw new Error('Format non supporté (JPEG, PNG, WebP uniquement)');
    }

    return await db.pendingPhotos.add({
        pendingBLId,
        blob,
        originalName,
        size: blob.size,
        createdAt: new Date().toISOString()
    });
}

// Helper : récupérer tous les BL en attente
export async function getPendingBLs() {
    return await db.pendingBL
        .where('status')
        .anyOf([BL_STATUS.PENDING, BL_STATUS.FAILED])
        .toArray();
}

// Helper : compter les BL en attente
export async function countPendingBLs() {
    return await db.pendingBL
        .where('status')
        .anyOf([BL_STATUS.PENDING, BL_STATUS.FAILED])
        .count();
}

// Helper : mettre à jour le statut d'un BL
export async function updateBLStatus(id, status) {
    return await db.pendingBL.update(id, {
        status,
        updatedAt: new Date().toISOString()
    });
}

// Helper : supprimer un BL et ses photos
export async function deletePendingBL(id) {
    await db.pendingPhotos.where('pendingBLId').equals(id).delete();
    await db.pendingBL.delete(id);
}

// Helper : nettoyer les BL synchronisés (> 24h)
export async function cleanupSyncedBLs() {
    const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
    const oldSynced = await db.pendingBL
        .where('status')
        .equals(BL_STATUS.SYNCED)
        .filter(bl => bl.updatedAt < oneDayAgo)
        .toArray();

    for (const bl of oldSynced) {
        await deletePendingBL(bl.id);
    }
    return oldSynced.length;
}

// Helper : vérifier le quota de stockage
export async function checkStorageQuota() {
    if ('storage' in navigator && 'estimate' in navigator.storage) {
        const estimate = await navigator.storage.estimate();
        const percentUsed = (estimate.usage / estimate.quota) * 100;
        return {
            used: estimate.usage,
            quota: estimate.quota,
            percentUsed: percentUsed.toFixed(1),
            warning: percentUsed > 80
        };
    }
    return null;
}

// Helper : incrémenter le retryCount d'un BL (atomique)
export async function incrementRetryCount(id) {
    return await db.pendingBL.where('id').equals(id).modify(bl => {
        bl.retryCount = (bl.retryCount || 0) + 1;
        bl.updatedAt = new Date().toISOString();
    });
}

// Helper : récupérer les BL prêts à synchroniser (PENDING + FAILED avec retryCount < 3) avec photos jointes
export async function getBLsReadyToSync() {
    const bls = await db.pendingBL
        .where('status')
        .anyOf([BL_STATUS.PENDING, BL_STATUS.FAILED])
        .filter(bl => (bl.retryCount || 0) < 3)
        .toArray();

    return await Promise.all(bls.map(async (bl) => {
        const photos = await db.pendingPhotos.where('pendingBLId').equals(bl.id).toArray();
        return { ...bl, photos };
    }));
}

// Cache des référentiels
export async function cacheReferentiels(key, data) {
    await db.referentiels.put({
        key,
        data,
        updatedAt: new Date().toISOString()
    });
}

export async function getCachedReferentiels(key, maxAgeHours = 24) {
    const cached = await db.referentiels.get(key);
    if (!cached) return null;

    const age = Date.now() - new Date(cached.updatedAt).getTime();
    const maxAge = maxAgeHours * 60 * 60 * 1000;

    if (age > maxAge) return null; // Expiré
    return cached.data;
}

// ── Cache BL validés ──

const BL_SYNC_TIME_KEY = 'lastBLSyncTime';

export async function cacheBLs(bls) {
    const now = new Date().toISOString();
    await db.cachedBLs.bulkPut(
        bls.map(bl => ({
            id: bl.id,
            etablissementId: bl.etablissement.id,
            statut: bl.statut,
            validatedAt: bl.validatedAt,
            cachedAt: now,
            data: bl,
        }))
    );
}

export async function getCachedBLs(etablissementId) {
    let query = db.cachedBLs.orderBy('validatedAt').reverse();
    if (etablissementId) {
        query = db.cachedBLs.where('etablissementId').equals(etablissementId).reverse();
    }
    const results = await query.toArray();
    if (etablissementId) {
        results.sort((a, b) => (b.validatedAt || '').localeCompare(a.validatedAt || ''));
    }
    return results.map(r => r.data);
}

export async function getCachedBL(blId) {
    const cached = await db.cachedBLs.get(blId);
    return cached ? cached.data : null;
}

export async function cacheBLImage(blId, blob) {
    const now = new Date().toISOString();
    await db.cachedBLImages.put({
        blId,
        blob,
        cachedAt: now,
        lastAccessedAt: now,
    });
}

export async function getCachedBLImage(blId) {
    const cached = await db.cachedBLImages.get(blId);
    if (cached) {
        // Update last accessed time
        await db.cachedBLImages.update(blId, { lastAccessedAt: new Date().toISOString() });
        return cached.blob;
    }
    return null;
}

export async function getLastBLSyncTime() {
    const record = await db.referentiels.get(BL_SYNC_TIME_KEY);
    return record ? record.data : null;
}

export async function setLastBLSyncTime(isoString) {
    await db.referentiels.put({
        key: BL_SYNC_TIME_KEY,
        data: isoString,
        updatedAt: isoString,
    });
}

export async function evictOldBLImages(max = 50) {
    const count = await db.cachedBLImages.count();
    if (count <= max) return 0;

    const toEvict = count - max;
    const oldest = await db.cachedBLImages.orderBy('lastAccessedAt').limit(toEvict).toArray();
    const ids = oldest.map(img => img.blId);
    await db.cachedBLImages.bulkDelete(ids);
    return ids.length;
}

export async function countCachedBLs() {
    return await db.cachedBLs.count();
}

export async function clearBLCache() {
    await db.cachedBLs.clear();
    await db.cachedBLImages.clear();
    await db.referentiels.delete(BL_SYNC_TIME_KEY);
}
