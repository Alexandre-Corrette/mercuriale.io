import { cacheReferentiels, getCachedReferentiels } from './db.js';

const CACHE_KEY = 'referentiels';
const CACHE_MAX_AGE_HOURS = 24;

export async function loadReferentiels(force = false) {
    // Vérifier le cache d'abord (sauf si force refresh)
    if (!force) {
        const cached = await getCachedReferentiels(CACHE_KEY, CACHE_MAX_AGE_HOURS);
        if (cached) {
            console.log('[Referentiels] Chargé depuis cache');
            return cached;
        }
    }

    // Charger depuis l'API
    if (!navigator.onLine) {
        console.log('[Referentiels] Offline, impossible de rafraîchir');
        return await getCachedReferentiels(CACHE_KEY, Infinity); // Retourne le cache même expiré
    }

    try {
        const response = await fetch('/api/referentiels/offline', {
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();

        // Sauvegarder en cache
        await cacheReferentiels(CACHE_KEY, data);
        console.log('[Referentiels] Chargé depuis API et mis en cache');

        return data;
    } catch (error) {
        console.error('[Referentiels] Erreur chargement:', error);

        // Fallback sur le cache même expiré
        return await getCachedReferentiels(CACHE_KEY, Infinity);
    }
}

export async function getEtablissements() {
    const data = await loadReferentiels();
    return data?.etablissements || [];
}

export async function getFournisseurs() {
    const data = await loadReferentiels();
    return data?.fournisseurs || [];
}
