/**
 * Network Probe — reliable online detection for Safari/iOS.
 *
 * navigator.onLine is unreliable on iOS Safari (always returns true on WiFi).
 * This module does a real HTTP HEAD probe against /manifest.json to confirm connectivity.
 */

let _probeResult = null;
let _probeExpiresAt = 0;

const PROBE_URL = '/manifest.json';
const PROBE_TIMEOUT = 5000; // 5s
const PROBE_CACHE_TTL = 10000; // 10s

/**
 * Check if the device is truly online.
 * Fast-path: if navigator.onLine === false, return false immediately.
 * Otherwise, do a real HTTP HEAD probe with caching.
 *
 * @returns {Promise<boolean>}
 */
export async function isOnline() {
    // Fast-path: browser says offline → trust it
    if (!navigator.onLine) {
        return false;
    }

    // Return cached probe result if still fresh
    if (_probeResult !== null && Date.now() < _probeExpiresAt) {
        return _probeResult;
    }

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), PROBE_TIMEOUT);

        const response = await fetch(PROBE_URL, {
            method: 'HEAD',
            cache: 'no-store',
            signal: controller.signal,
        });

        clearTimeout(timeoutId);

        _probeResult = response.ok;
        _probeExpiresAt = Date.now() + PROBE_CACHE_TTL;
        return _probeResult;
    } catch {
        _probeResult = false;
        _probeExpiresAt = Date.now() + PROBE_CACHE_TTL;
        return false;
    }
}

/**
 * Clear the probe cache so the next isOnline() call does a fresh probe.
 * Call this on online/offline events.
 */
export function invalidateProbe() {
    _probeResult = null;
    _probeExpiresAt = 0;
}
