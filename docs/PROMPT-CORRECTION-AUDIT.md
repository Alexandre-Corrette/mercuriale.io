# Prompt de correction — Audit Mercuriale.io

Tu es un développeur Symfony 7 senior. Tu dois corriger les défauts identifiés dans l'audit de santé du projet Mercuriale.io (`docs/AUDIT-SANTE-2026-02-25.md`).

## Contexte technique
- Stack : PHP 8.3, Symfony 7, API Platform, Doctrine ORM, PostgreSQL
- Auth : LexikJWTAuthenticationBundle (RS256), refresh tokens BDD
- Frontend : Twig + Tailwind CSS v4 + Stimulus, CSS dans fichiers `.css` séparés uniquement (INTERDIT inline)
- Infra : OVH mutualisé
- Git : branches depuis `develop`, convention `feature/MERC-XXX-description`

## Règles strictes
1. **CSS** : INTERDIT les balises `<style>` et attributs `style=""` dans le HTML. Tout va dans des fichiers `.css` séparés.
2. **Pas de sur-ingénierie** : ne corrige que ce qui est listé. Ne refactorise pas le code environnant.
3. **Tests** : si des tests existent pour le code modifié, assure-toi qu'ils passent toujours. N'ajoute pas de tests sauf si explicitement demandé.
4. **Commits** : un commit par issue, message en anglais, format `fix(scope): description [MERC-XXX]`.
5. **Linear** : utilise `linear issue create` (ou l'API Linear) pour créer chaque issue AVANT de commencer le code.

---

## Procédure pour CHAQUE issue

```
1. Créer l'issue sur Linear (team MERC, labels appropriés)
2. git checkout develop && git pull origin develop
3. git checkout -b feature/MERC-XXX-description-courte
4. Implémenter le fix
5. Vérifier : php bin/console lint:container && php bin/console lint:twig templates/
6. git add <fichiers modifiés> && git commit -m "fix(scope): description [MERC-XXX]"
7. git push -u origin feature/MERC-XXX-description-courte
8. Passer à l'issue suivante
```

---

## ISSUE 1 — 🔴 P0 : Fix IDOR PushController (subscribe + unsubscribe)

**Linear** :
- Titre : `Fix IDOR vulnerability in PushController subscribe/unsubscribe`
- Label : `bug`, `security`, `P0`
- Description : `Audit #1 #2 — PushController::subscribe écrase le user d'une subscription existante. PushController::unsubscribe supprime une subscription sans vérifier l'appartenance.`

**Branche** : `feature/MERC-XXX-fix-idor-push-controller`

**Fichiers à modifier** :
- `src/Controller/Api/PushController.php`
- `src/Repository/PushSubscriptionRepository.php`

**Corrections** :

### PushController.php — `subscribe()` (ligne 60-67)
Remplacer le bloc upsert par :
```php
$subscription = $this->subscriptionRepository->findByEndpoint($endpoint);

if ($subscription !== null) {
    // Only allow updating own subscription
    if ($subscription->getUtilisateur() !== $user) {
        // Another user owns this endpoint — create a new subscription
        // (browser re-registration after cookie clear)
        $this->subscriptionRepository->deleteByEndpointAndUser($endpoint, $subscription->getUtilisateur());
        $subscription = null;
    }
}

if ($subscription !== null) {
    $subscription->setP256dhKey($p256dh);
    $subscription->setAuthToken($auth);
    $subscription->setUserAgent($request->headers->get('User-Agent'));
} else {
    $subscription = new PushSubscription();
    $subscription->setUtilisateur($user);
    $subscription->setEndpoint($endpoint);
    $subscription->setP256dhKey($p256dh);
    $subscription->setAuthToken($auth);
    $subscription->setUserAgent($request->headers->get('User-Agent'));
    $this->entityManager->persist($subscription);
}
```

### PushController.php — `unsubscribe()` (ligne 105)
Remplacer :
```php
$this->subscriptionRepository->deleteByEndpoint($endpoint);
```
Par :
```php
$this->subscriptionRepository->deleteByEndpointAndUser($endpoint, $user);
```

### PushSubscriptionRepository.php
Ajouter la méthode :
```php
public function deleteByEndpointAndUser(string $endpoint, Utilisateur $user): void
{
    $this->createQueryBuilder('s')
        ->delete()
        ->where('s.endpoint = :endpoint')
        ->andWhere('s.utilisateur = :user')
        ->setParameter('endpoint', $endpoint)
        ->setParameter('user', $user)
        ->getQuery()
        ->execute();
}
```

Aussi ajouter le rate limiting sur `unsubscribe` (audit #15) :
```php
$limiter = $this->apiLimiter->create('api_push_' . $user->getId());
if (!$limiter->consume()->isAccepted()) {
    return $this->json(['success' => false, 'error' => 'Trop de requêtes.'], Response::HTTP_TOO_MANY_REQUESTS);
}
```

---

## ISSUE 2 — 🔴 P0 : Fix validation + CSRF sur corrigerLigne

**Linear** :
- Titre : `Add input validation and CSRF protection to corrigerLigne endpoint`
- Label : `bug`, `security`, `P0`
- Description : `Audit #9 #12 — Les valeurs JSON (quantite_livree, prix_unitaire, total_ligne) ne sont pas validées. Pas de token CSRF sur cet endpoint POST qui modifie des données financières.`

**Branche** : `feature/MERC-XXX-fix-validation-csrf-corriger-ligne`

**Fichiers à modifier** :
- `src/Controller/App/BonLivraisonController.php` — méthode `corrigerLigne`

**Corrections** :

Ajouter en début de méthode, après le décodage JSON :
```php
// CSRF verification via header
$csrfToken = $request->headers->get('X-CSRF-Token');
if (!$this->isCsrfTokenValid('corriger_ligne_' . $bonLivraison->getId(), $csrfToken)) {
    return new JsonResponse(['success' => false, 'error' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
}
```

Ajouter la validation des valeurs numériques avant chaque setter :
```php
if (isset($data['quantite_livree'])) {
    if (!is_numeric($data['quantite_livree']) || (float) $data['quantite_livree'] < 0) {
        return new JsonResponse(['success' => false, 'error' => 'Quantité livrée invalide.'], Response::HTTP_BAD_REQUEST);
    }
    $ligne->setQuantiteLivree((string) $data['quantite_livree']);
}

if (isset($data['prix_unitaire'])) {
    if (!is_numeric($data['prix_unitaire']) || (float) $data['prix_unitaire'] < 0) {
        return new JsonResponse(['success' => false, 'error' => 'Prix unitaire invalide.'], Response::HTTP_BAD_REQUEST);
    }
    $ligne->setPrixUnitaire((string) $data['prix_unitaire']);
}

if (isset($data['total_ligne'])) {
    if (!is_numeric($data['total_ligne']) || (float) $data['total_ligne'] < 0) {
        return new JsonResponse(['success' => false, 'error' => 'Total ligne invalide.'], Response::HTTP_BAD_REQUEST);
    }
    $ligne->setTotalLigne((string) $data['total_ligne']);
}
```

**Côté JS** (fichier Stimulus correspondant à l'extraction) : envoyer le header CSRF dans les requêtes fetch :
```js
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
fetch(url, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(data),
});
```

Et ajouter dans le template Twig concerné :
```twig
<meta name="csrf-token" content="{{ csrf_token('corriger_ligne_' ~ bonLivraison.id) }}">
```

---

## ISSUE 3 — 🔴 P0 : Add #[Ignore] on sensitive entity fields

**Linear** :
- Titre : `Protect sensitive fields from serialization exposure`
- Label : `bug`, `security`, `P0`
- Description : `Audit #38 #39 #40 #41 #42 — Champs sensibles ($password, $p256dhKey, $authToken, $ipAddress, $donneesBrutes) sans protection de sérialisation.`

**Branche** : `feature/MERC-XXX-protect-sensitive-serialization`

**Fichiers à modifier** :
- `src/Entity/Utilisateur.php` — ajouter `#[Ignore]` sur `$password`
- `src/Entity/PushSubscription.php` — ajouter `#[Ignore]` sur `$p256dhKey` et `$authToken`
- `src/Entity/AuditLog.php` — ajouter `#[Ignore]` sur `$ipAddress` et `$changes`
- `src/Entity/LoginLog.php` — ajouter `#[Ignore]` sur `$ipAddress` et `$userAgent`
- `src/Entity/BonLivraison.php` — ajouter `#[Ignore]` sur `$donneesBrutes`

**Import requis** dans chaque fichier :
```php
use Symfony\Component\Serializer\Attribute\Ignore;
```

**Exemple pour Utilisateur.php** :
```php
#[ORM\Column]
#[Ignore]
private ?string $password = null;
```

---

## ISSUE 4 — 🔴 P1 : Add transactions on critical service paths

**Linear** :
- Titre : `Wrap critical operations in explicit database transactions`
- Label : `bug`, `data-integrity`, `P1`
- Description : `Audit #50 #51 #52 #53 #54 — ControleService, BonLivraisonExtractorService et BonLivraisonController ont des opérations multi-flush sans transaction. Risque d'état BDD incohérent.`

**Branche** : `feature/MERC-XXX-add-transactions-critical-paths`

**Fichiers à modifier** :

### `src/Service/Controle/ControleService.php`
Envelopper `controlerBonLivraison()` dans une transaction :
```php
public function controlerBonLivraison(BonLivraison $bonLivraison): void
{
    $this->entityManager->beginTransaction();
    try {
        // ... code existant de contrôle ...
        $this->entityManager->flush();
        $this->entityManager->commit();
    } catch (\Throwable $e) {
        $this->entityManager->rollback();
        throw $e;
    }
}
```

### `src/Service/Ocr/BonLivraisonExtractorService.php`
Envelopper `extract()` dans une transaction (autour des persist/flush) :
```php
$this->entityManager->beginTransaction();
try {
    // ... création lignes BL, mise à jour BL et fournisseur ...
    $this->entityManager->flush();
    $this->entityManager->commit();
} catch (\Throwable $e) {
    $this->entityManager->rollback();
    throw $e;
}
```

### `src/Controller/App/BonLivraisonController.php`
- **`valider()`** : envelopper dans une transaction englobante (contrôle + mise à jour statut)
- **`corrigerLigne()`** : envelopper dans une transaction (correction + re-contrôle)
- **`rejeter()`** : déplacer `unlink($imagePath)` APRÈS le `flush()` réussi

---

## ISSUE 5 — 🔴 P1 : Sync OrganisationFournisseur entity with DB schema

**Linear** :
- Titre : `Sync OrganisationFournisseur onDelete mapping with actual database schema`
- Label : `bug`, `data-integrity`, `P1`
- Description : `Audit #56 — Entity dit RESTRICT (implicite), BDD dit CASCADE. Risque de migration destructive.`

**Branche** : `feature/MERC-XXX-sync-organisation-fournisseur-ondelete`

**Fichier** : `src/Entity/OrganisationFournisseur.php`

Modifier les deux JoinColumn :
```php
#[ORM\ManyToOne(targetEntity: Organisation::class, inversedBy: 'organisationFournisseurs')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private ?Organisation $organisation = null;

#[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'organisationFournisseurs')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private ?Fournisseur $fournisseur = null;
```

Puis vérifier : `php bin/console doctrine:schema:validate` — doit être "in sync".

---

## ISSUE 6 — 🟠 P1 : Add missing onDelete on all ManyToOne relations

**Linear** :
- Titre : `Add onDelete declarations to all ManyToOne JoinColumns`
- Label : `tech-debt`, `data-integrity`, `P1`
- Description : `Audit #57-65 — 10 relations ManyToOne sans onDelete configuré. Risque d'erreur FK ou d'orphelins en BDD.`

**Branche** : `feature/MERC-XXX-add-ondelete-all-relations`

**Fichiers et modifications** :

| Fichier | Relation | onDelete à ajouter |
|---------|----------|-------------------|
| `Etablissement.php:28` | `→ Organisation` | `onDelete: 'CASCADE'` |
| `Utilisateur.php:32` | `→ Organisation` | `onDelete: 'CASCADE'` |
| `UtilisateurEtablissement.php:26` | `→ Utilisateur` | `onDelete: 'CASCADE'` |
| `UtilisateurEtablissement.php:31` | `→ Etablissement` | `onDelete: 'CASCADE'` |
| `BonLivraison.php:33` | `→ Etablissement` | `onDelete: 'RESTRICT'` |
| `BonLivraison.php:38` | `→ Fournisseur` | `onDelete: 'SET NULL'` |
| `ProduitFournisseur.php:35` | `→ Fournisseur` | `onDelete: 'CASCADE'` |
| `ProduitFournisseur.php:50` | `→ Unite` | `onDelete: 'RESTRICT'` |
| `Mercuriale.php:29` | `→ ProduitFournisseur` | `onDelete: 'CASCADE'` |
| `MercurialeImport.php:32` | `→ Fournisseur` | `onDelete: 'CASCADE'` |
| `MercurialeImport.php:42` | `→ Utilisateur` | `onDelete: 'CASCADE'` |
| `Produit.php:41` | `→ Unite` | `onDelete: 'RESTRICT'` |
| `LigneBonLivraison.php:54` | `→ Unite` | `onDelete: 'RESTRICT'` |

Ensuite :
```bash
php bin/console doctrine:migrations:diff
# Vérifier la migration générée, puis :
php bin/console doctrine:schema:validate
```

---

## ISSUE 7 — 🟠 P2 : Harden CSP — remove unsafe-inline and data: from script-src

**Linear** :
- Titre : `Harden Content Security Policy: remove unsafe-inline and data: from script-src`
- Label : `security`, `P2`
- Description : `Audit #20 #60 — CSP actuelle contient 'unsafe-inline' et data: dans script-src. Permet injection XSS via inline scripts et data: URIs.`

**Branche** : `feature/MERC-XXX-harden-csp-nonces`

**Fichiers à modifier** :
- `src/EventListener/SecurityHeadersListener.php`
- `src/Twig/CspNonceExtension.php` (à créer)
- `templates/base.html.twig`

**Plan** :
1. Créer une Twig Extension qui génère un nonce unique par requête
2. Injecter le nonce dans le CSP header : `script-src 'self' 'nonce-<value>'`
3. Ajouter `nonce="{{ csp_nonce() }}"` sur tous les `<script>` des templates
4. Supprimer `'unsafe-inline'` et `data:` de `script-src`
5. Garder `data:` uniquement dans `img-src` et `font-src`

---

## ISSUE 8 — 🟠 P2 : Add #[IsGranted] on EasyAdmin CRUD controllers

**Linear** :
- Titre : `Add explicit IsGranted attributes on EasyAdmin CRUD controllers`
- Label : `security`, `P2`
- Description : `Audit #4 #5 — BonLivraisonCrudController et AlerteControleCrudController accessibles par URL directe sans vérification ROLE_SUPER_ADMIN.`

**Branche** : `feature/MERC-XXX-add-isgranted-crud-controllers`

**Fichiers à modifier** :
- `src/Controller/Admin/BonLivraisonCrudController.php` — ajouter `#[IsGranted('ROLE_SUPER_ADMIN')]`
- `src/Controller/Admin/AlerteControleCrudController.php` — ajouter `#[IsGranted('ROLE_SUPER_ADMIN')]`
- `src/Controller/Admin/LigneBonLivraisonCrudController.php` — vérifier et ajouter si manquant
- Vérifier TOUS les autres CRUD controllers dans `src/Controller/Admin/` et ajouter `#[IsGranted]` si manquant

Aussi corriger le dashboard (#27) :
- `templates/admin/dashboard.html.twig` : entourer les 3 cards portail de `{% if is_granted('ROLE_MANAGER') %}`

---

## ISSUE 9 — 🟠 P2 : Fix hardcoded URLs and routes

**Linear** :
- Titre : `Replace hardcoded URLs with Symfony path() and linkToRoute()`
- Label : `tech-debt`, `P2`
- Description : `Audit #25 #26 — URLs hardcodées dans base.html.twig et DashboardController au lieu d'utiliser path() ou linkToRoute().`

**Branche** : `feature/MERC-XXX-fix-hardcoded-urls`

**Fichiers à modifier** :

### `templates/base.html.twig` (ligne 36)
```twig
{# Avant #}
href="/app/pending"
{# Après #}
href="{{ path('app_pending') }}"
```

### `src/Controller/Admin/DashboardController.php` (lignes 83, 93)
```php
// Avant
MenuItem::linkToUrl('Uploader un BL', 'fas fa-camera', '/app/bl/upload')
MenuItem::linkToUrl('Import mercuriale', 'fas fa-file-excel', '/app/mercuriale/import')
// Après
MenuItem::linkToRoute('Uploader un BL', 'fas fa-camera', 'app_bl_upload')
MenuItem::linkToRoute('Import mercuriale', 'fas fa-file-excel', 'app_mercuriale_import')
```

---

## ISSUE 10 — 🟠 P2 : Disable API Platform docs in production

**Linear** :
- Titre : `Disable API Platform docs endpoint in production and fix default config`
- Label : `security`, `P2`
- Description : `Audit #7 #35 #36 #37 — /api/docs publique, titre "Hello API Platform", pas de defaults.security, bundle non utilisé.`

**Branche** : `feature/MERC-XXX-secure-api-platform-config`

**Fichier** : `config/packages/api_platform.yaml`
```yaml
api_platform:
    title: 'Mercuriale.io API'
    version: '1.0.0'
    defaults:
        security: "is_granted('ROLE_USER')"

when@prod:
    api_platform:
        enable_docs: false
```

---

## ISSUE 11 — 🟠 P3 : Fix CSS inline in DashboardController and hardcoded asset paths

**Linear** :
- Titre : `Remove CSS inline from DashboardController and fix hardcoded asset paths`
- Label : `tech-debt`, `frontend`, `P3`
- Description : `Audit #28 #29 — CSS inline dans setTitle() du DashboardController. Chemin CSS hardcodé dans extraction.html.twig.`

**Branche** : `feature/MERC-XXX-fix-css-inline-asset-paths`

**Fichiers à modifier** :

### `src/Controller/Admin/DashboardController.php` (ligne 68)
```php
// Avant
->setTitle('<img src="/images/logo-rectangulaire-mercuriale.jpg" alt="Mercuriale.io" style="width: 200px; height: 45px; object-fit: cover; object-position: center;">')
// Après
->setTitle('<img src="/images/logo-rectangulaire-mercuriale.jpg" alt="Mercuriale.io" class="ea-dashboard-logo">')
```

### `public/css/admin.css` — ajouter :
```css
.ea-dashboard-logo {
    width: 200px;
    height: 45px;
    object-fit: cover;
    object-position: center;
}
```

### `templates/app/bon_livraison/extraction.html.twig` (ligne 7)
```twig
{# Avant #}
href="/css/extraction.css"
{# Après #}
href="{{ asset('css/extraction.css') }}"
```

---

## ISSUE 12 — 🟠 P3 : Update SW pre-cache and fix palette duplication

**Linear** :
- Titre : `Add missing CSS files to SW pre-cache and consolidate color palette`
- Label : `tech-debt`, `frontend`, `P3`
- Description : `Audit #30 #31 #32 — mercuriale-import.css et admin-dashboard.css manquants du pré-cache SW. Palette de couleurs dupliquée entre Tailwind et admin.css.`

**Branche** : `feature/MERC-XXX-fix-sw-precache-palette`

**Fichiers à modifier** :

### `public/sw.js` — ajouter dans `APP_SHELL_FILES` :
```js
'/css/mercuriale-import.css',
'/css/admin-dashboard.css',
```

### `public/css/admin.css` — remplacer les custom properties dupliquées par des références Tailwind :
Supprimer les lignes 9-20 (custom properties --color-navy, --color-coral, etc.) et les remplacer par les valeurs Tailwind utilisées partout ailleurs.

---

## ISSUE 13 — 🟠 P3 : Clean up dead code and orphan templates

**Linear** :
- Titre : `Remove dead code: orphan template, empty ApiResource folder, dead POST block`
- Label : `tech-debt`, `P3`
- Description : `Audit #24 #34 #68 #69 #70 — Template validate.html.twig orphelin (Bootstrap), dossier ApiResource vide, bloc POST mort dans MercurialeImportController.`

**Branche** : `feature/MERC-XXX-remove-dead-code`

**Actions** :
1. `git rm templates/app/bon_livraison/validate.html.twig`
2. `git rm src/ApiResource/.gitignore && rmdir src/ApiResource/`
3. Supprimer le bloc POST mort dans `src/Controller/App/MercurialeImportController.php` (lignes 284-293)

---

## ISSUE 14 — 🟠 P3 : Add missing database indexes

**Linear** :
- Titre : `Add missing indexes on frequently queried columns`
- Label : `tech-debt`, `performance`, `P3`
- Description : `Audit #45 #46 #47 #48 #49 — Index manquants sur numero_bl, created_at (alertes), actif (fournisseur, produit, produit_fournisseur).`

**Branche** : `feature/MERC-XXX-add-missing-indexes`

**Fichiers à modifier** :

### `src/Entity/BonLivraison.php`
Ajouter dans les attributs de classe :
```php
#[ORM\Index(columns: ['numero_bl'], name: 'idx_bl_numero')]
```

### `src/Entity/AlerteControle.php`
```php
#[ORM\Index(columns: ['created_at'], name: 'idx_alerte_created')]
```

### `src/Entity/Fournisseur.php`
```php
#[ORM\Index(columns: ['actif', 'nom'], name: 'idx_fournisseur_actif_nom')]
```

### `src/Entity/Produit.php`
```php
#[ORM\Index(columns: ['actif'], name: 'idx_produit_actif')]
```

### `src/Entity/ProduitFournisseur.php`
```php
#[ORM\Index(columns: ['actif'], name: 'idx_produit_fournisseur_actif')]
```

Puis :
```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:schema:validate
```

---

## ISSUE 15 — 🟡 P3 : Fix N+1 queries in BL API serialization

**Linear** :
- Titre : `Optimize BL API serialization to prevent N+1 queries`
- Label : `performance`, `P3`
- Description : `Audit #73 #74 — serializeBL() itère sur lignes/alertes avec lazy-loading. Risque N+1.`

**Branche** : `feature/MERC-XXX-fix-n1-bl-serialization`

**Fichier** : `src/Repository/BonLivraisonRepository.php`

Vérifier `findValidatedForUser` et ajouter si manquant :
```php
->leftJoin('bl.lignes', 'l')->addSelect('l')
->leftJoin('l.alertes', 'a')->addSelect('a')
->leftJoin('l.unite', 'u')->addSelect('u')
->leftJoin('bl.fournisseur', 'f')->addSelect('f')
->leftJoin('bl.etablissement', 'e')->addSelect('e')
```

---

## ISSUE 16 — 🟡 P3 : Fix .env template and minor config issues

**Linear** :
- Titre : `Fix .env template DATABASE_URL and minor config inconsistencies`
- Label : `tech-debt`, `P3`
- Description : `Audit #75 #76 #8 #19 — DATABASE_URL template MySQL au lieu de PostgreSQL. API version par défaut. ROLE_OPERATOR non documenté. APP_SECRET check.`

**Branche** : `feature/MERC-XXX-fix-env-config`

**Fichiers à modifier** :

### `.env` (ligne 37)
```bash
# Avant
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0.32&charset=utf8mb4"
# Après
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/mercuriale?serverVersion=16&charset=utf8"
```

### `config/packages/security.yaml` — documenter ROLE_OPERATOR dans role_hierarchy :
```yaml
role_hierarchy:
    ROLE_SUPER_ADMIN: ROLE_ADMIN
    ROLE_ADMIN: ROLE_MANAGER
    ROLE_MANAGER: ROLE_OPERATOR
    ROLE_OPERATOR: ROLE_USER
```

---

## ISSUE 17 — 🟡 P3 : Add pagination to offline referentiel endpoint

**Linear** :
- Titre : `Add limit to offline referentiel API endpoint`
- Label : `performance`, `P3`
- Description : `Audit #43 — /api/referentiels/offline retourne TOUS les fournisseurs actifs sans limite.`

**Branche** : `feature/MERC-XXX-limit-offline-referentiel`

**Fichier** : `src/Controller/Api/ReferentielController.php`

Ajouter `setMaxResults(500)` ou pagination sur la requête qui charge les fournisseurs actifs.

---

## ISSUE 18 — 🟡 P3 : Review orphanRemoval on Organisation entity

**Linear** :
- Titre : `Review orphanRemoval safety on Organisation.etablissements and .utilisateurs`
- Label : `data-integrity`, `P3`
- Description : `Audit #66 #67 — orphanRemoval: true sur etablissements et utilisateurs dans Organisation. Retirer un item de la collection le SUPPRIME avec toutes ses données.`

**Branche** : `feature/MERC-XXX-review-orphan-removal-organisation`

**Fichier** : `src/Entity/Organisation.php`

**Décision requise** :
- Option A : Retirer `orphanRemoval: true` et gérer les suppressions explicitement
- Option B : Ajouter un soft-delete (`actif=false`) sur Etablissement et Utilisateur
- Option C : Garder tel quel si la suppression est intentionnelle (documenter le comportement)

---

## Résumé des issues à créer

| # | Priorité | Titre court | Labels |
|---|----------|------------|--------|
| 1 | 🔴 P0 | Fix IDOR PushController | security, bug |
| 2 | 🔴 P0 | Validation + CSRF corrigerLigne | security, bug |
| 3 | 🔴 P0 | Protect sensitive serialization | security, bug |
| 4 | 🔴 P1 | Add transactions critical paths | data-integrity, bug |
| 5 | 🔴 P1 | Sync OrganisationFournisseur onDelete | data-integrity, bug |
| 6 | 🟠 P1 | Add onDelete all relations | data-integrity, tech-debt |
| 7 | 🟠 P2 | Harden CSP nonces | security |
| 8 | 🟠 P2 | IsGranted CRUD controllers | security |
| 9 | 🟠 P2 | Fix hardcoded URLs | tech-debt |
| 10 | 🟠 P2 | Secure API Platform config | security |
| 11 | 🟠 P3 | Fix CSS inline + asset paths | frontend, tech-debt |
| 12 | 🟠 P3 | SW pre-cache + palette | frontend, tech-debt |
| 13 | 🟠 P3 | Remove dead code | tech-debt |
| 14 | 🟠 P3 | Add missing indexes | performance, tech-debt |
| 15 | 🟡 P3 | Fix N+1 BL serialization | performance |
| 16 | 🟡 P3 | Fix .env + config | tech-debt |
| 17 | 🟡 P3 | Limit offline referentiel | performance |
| 18 | 🟡 P3 | Review orphanRemoval | data-integrity |

**Ordre d'exécution** : Issues 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14 → 15 → 16 → 17 → 18
