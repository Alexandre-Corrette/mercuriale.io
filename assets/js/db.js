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
