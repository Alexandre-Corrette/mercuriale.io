# PWA Sprint 6 — Test Checklist

## Safari/iOS Hardening

- [ ] **Offline detection**: Put iPhone in airplane mode. Within 30 seconds, the offline banner appears.
- [ ] **Online recovery**: Turn airplane mode off. Sync resumes automatically, banner hides.
- [ ] **Session persistence**: Open PWA from home screen, close it, reopen. Session should persist. If not, re-login banner shows.
- [ ] **Auth lost banner**: If session cookie is cleared (iOS PWA restart), the "Session expirée" banner appears with a link to `/login`.
- [ ] **ObjectURL cleanup**: Navigate to BL detail, go back, check DevTools memory — no blob leak.

## Quota Management

- [ ] **Emergency eviction**: Fill storage near limit. On next write, eviction keeps max 10 images + 20 cached BLs.
- [ ] **Critical banner**: If eviction fails and storage is still full, red "Stockage critique" banner appears.
- [ ] **safeWrite retry**: After eviction, the write operation is retried once automatically.

## Lighthouse PWA Audit

- [ ] Run Lighthouse in Chrome DevTools (Incognito, Mobile preset).
- [ ] **PWA score > 90**.
- [ ] No "missing screenshots" warning in manifest.
- [ ] No "missing scope" warning.
- [ ] No inline scripts detected in offline.html.
- [ ] `meta name="description"` present.

## Install Prompt (A2HS)

- [ ] **Android/Chrome**: On first eligible visit, install banner slides up after 2 seconds. "Installer" triggers native install dialog.
- [ ] **iOS Safari**: Banner shows Share instructions ("Partager > Sur l'écran d'accueil").
- [ ] **Dismiss cooldown**: After dismissing, banner does not reappear for 14 days.
- [ ] **Already installed**: If opened as standalone PWA, no install banner shows.
- [ ] **Not logged in**: Install prompt only appears for authenticated users.

## Service Worker

- [ ] Check DevTools > Application > Service Workers: version is `mercuriale-v1.5.0`.
- [ ] `offline-retry.js` and `install-prompt.css` are in the precache.
- [ ] Offline page loads correctly and shows pending BL count from IndexedDB.
- [ ] Retry button on offline page reloads the page.

## Manifest

- [ ] DevTools > Application > Manifest: no warnings.
- [ ] Screenshots, shortcuts, scope, id all present.
- [ ] Shortcuts: "Scanner un BL" and "BL en attente" appear (Android).

## Screenshots

- [ ] Run `php bin/console app:generate-pwa-screenshots`.
- [ ] `public/icons/screenshot-wide.png` (1280x720) and `screenshot-narrow.png` (750x1334) exist.
