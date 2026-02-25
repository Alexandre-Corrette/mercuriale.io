# Rapport d'Audit de Santé — Mercuriale.io

**Date** : 25 février 2026
**Auditeur** : Claude (audit automatisé complet)
**Stack** : PHP 8.3, Symfony 7, API Platform, Doctrine ORM, MySQL/MariaDB
**Auth** : LexikJWTAuthenticationBundle (RS256), refresh tokens BDD
**Frontend** : Twig + Tailwind CSS v4 + Stimulus, CSS fichiers séparés
**Infra** : OVH mutualisé

---

## 1. SÉCURITÉ

### 1.1 Protection IDOR

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 1 | 🔴 CRITIQUE | `src/Controller/Api/PushController.php` | 89-113 | **IDOR sur `DELETE /api/push/unsubscribe`** : supprime TOUTE subscription push par endpoint URL sans vérifier l'appartenance à l'utilisateur courant. User A peut désabonner User B. | Changer `deleteByEndpoint($endpoint)` en `deleteByEndpointAndUser($endpoint, $user)` |
| 2 | 🔴 CRITIQUE | `src/Controller/Api/PushController.php` | 60-67 | **IDOR sur `POST /api/push/subscribe`** : écrase le `utilisateur` d'une subscription existante trouvée par endpoint. User A peut prendre le contrôle de la subscription push de User B. | Vérifier que `$subscription->getUtilisateur() === $user` avant de modifier. Si différent, créer une nouvelle subscription. |
| 3 | 🟡 INFO | `src/Controller/Api/BonLivraisonReadController.php` | 87-123 | `image` : charge BonLivraison par `{id}`, puis vérifie `isGranted('VIEW', $bl->getEtablissement())`. Correct mais permet l'énumération d'IDs (404 vs 403). | Utiliser des UUID au lieu d'IDs séquentiels pour les BL en API. |

**Bilan IDOR** : Le projet utilise systématiquement les Voters `EtablissementVoter` et `FournisseurVoter` sur chaque action. Bon niveau sauf PushController qui présente 2 failles IDOR critiques.

### 1.2 Contrôle d'accès

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 4 | 🟠 WARNING | `src/Controller/Admin/BonLivraisonCrudController.php` | 23 | Pas de `#[IsGranted]`. Le menu sidebar exige `ROLE_SUPER_ADMIN` (DashboardController.php:87) mais la route `/admin` exige seulement `ROLE_ADMIN`. Un `ROLE_ADMIN` peut accéder au CRUD BL par URL directe. | Ajouter `#[IsGranted('ROLE_SUPER_ADMIN')]` au contrôleur. |
| 5 | 🟠 WARNING | `src/Controller/Admin/AlerteControleCrudController.php` | 24 | Même problème : pas de `#[IsGranted]` mais le menu exige `ROLE_SUPER_ADMIN`. | Ajouter `#[IsGranted('ROLE_SUPER_ADMIN')]`. |
| 6 | 🟠 WARNING | `src/Controller/Api/TokenController.php` | 17 | Pas de `#[IsGranted]` au niveau classe. Incohérent avec les autres contrôleurs API. | Ajouter un commentaire ou `#[IsGranted('PUBLIC_ACCESS')]` sur `login()`. |
| 7 | 🟡 INFO | `config/packages/security.yaml` | 62 | `/api/docs` accessible sans auth (`PUBLIC_ACCESS`). Expose la surface API aux attaquants. | Restreindre en prod : `enable_docs: false` dans `api_platform.yaml` (when@prod). |
| 8 | 🟡 INFO | `src/Security/Voter/EtablissementVoter.php` | 68 | Utilise `ROLE_OPERATOR` non présent dans `role_hierarchy` de security.yaml. | Documenter ce rôle ou l'ajouter à la hiérarchie. |

### 1.3 Validation des entrées

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 9 | 🔴 CRITIQUE | `src/Controller/App/BonLivraisonController.php` | 353-361 | **`corrigerLigne` : valeurs JSON brutes** (`quantite_livree`, `prix_unitaire`, `total_ligne`) passées directement aux setters sans validation numérique. Un utilisateur peut injecter des valeurs non numériques ou négatives sur des données financières. | Valider avec `is_numeric()` + `$value >= 0` avant affectation, ou utiliser `$validator->validate()`. |
| 10 | 🟡 INFO | `src/Service/Upload/BonLivraisonUploadService.php` | 22-36 | Validation MIME + magic bytes + détection contenu suspect. Excellente implémentation. | RAS. |
| 11 | 🟡 INFO | `src/Service/Import/MercurialeFileParser.php` | 220-233 | Détection injection formules CSV (`=`, `+`, `-`, `@`) + `htmlspecialchars` sur toutes les cellules. | RAS — bien protégé. |

### 1.4 CSRF & Rate Limiting

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 12 | 🔴 CRITIQUE | `src/Controller/App/BonLivraisonController.php` | 328 | **`corrigerLigne` (POST JSON) : pas de vérification CSRF.** Endpoint qui modifie des données financières (prix, quantités de lignes BL). | Ajouter un header `X-CSRF-Token` vérifié côté serveur. |
| 13 | 🟡 INFO | `src/Controller/App/BonLivraisonController.php` | 245, 296 | `valider` et `rejeter` : CSRF vérifié via `isCsrfTokenValid`. | RAS. |
| 14 | 🟡 INFO | `config/packages/rate_limiter.yaml` | — | Rate limiting complet : login (5/15min), API (100/min), BL upload (10/min), import mercuriale (5/h). | RAS — configuration appropriée. |
| 15 | 🟠 WARNING | `src/Controller/Api/PushController.php` | 89 | `unsubscribe` : pas de rate limiting (contrairement à `subscribe`). Permet le mass-unsubscribe. | Ajouter rate limiting cohérent. |

### 1.5 Auth & JWT

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 16 | 🟡 INFO | `config/packages/lexik_jwt_authentication.yaml` | 5 | `token_ttl: 900` (15 min). Bonne pratique. | RAS. |
| 17 | 🟡 INFO | `config/packages/gesdinet_jwt_refresh_token.yaml` | 4 | `single_use: true`, cookie HttpOnly/Secure/SameSite=Strict. | RAS — excellente config. |
| 18 | 🟡 INFO | `src/EventListener/BlockRevokedRefreshTokenListener.php` | — | Listener bloquant les refresh tokens révoqués. | RAS. |
| 19 | 🟠 WARNING | `.env` | 19 | `APP_SECRET=` vide. Correct (doit être dans `.env.local`) mais risqué si `.env.local` manque en prod. | Ajouter un check de déploiement vérifiant `APP_SECRET`. |
| 20 | 🟠 WARNING | `src/EventListener/SecurityHeadersListener.php` | 38 | CSP prod contient **`'unsafe-inline'` et `data:`** dans `script-src`. Affaiblit considérablement la CSP. `data:` dans script-src permet l'injection JavaScript via URIs `data:text/html`. | Migrer vers des nonces CSP. Supprimer `data:` de `script-src` (garder seulement dans `img-src`/`font-src`). |

---

## 2. ROUTES & LIENS

### 2.1 Routes orphelines

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 21 | 🟡 INFO | `src/Controller/App/BonLivraisonController.php` | 328 | Route `app_bl_ligne_corriger` jamais appelée depuis un template ou JS. Endpoint JSON d'édition inline sans frontend correspondant. | Implémenter le JS d'appel ou supprimer la route. |
| 22 | 🟡 INFO | `src/Controller/Api/PushController.php` | 89 | Route `api_push_unsubscribe` jamais appelée depuis le JS. Seul `subscribe` est utilisé dans `push_notification_controller.js`. | Implémenter l'unsubscribe côté JS. |
| 23 | 🟡 INFO | `src/Controller/Api/TokenController.php` | 32, 61 | Routes `api_token_revoke` et `api_admin_revoke_user_tokens` jamais appelées depuis le frontend. | Endpoints internes/admin. Documenter l'intention. |

### 2.2 Liens & Redirections

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 24 | 🔴 CRITIQUE | `src/Controller/App/MercurialeImportController.php` | 292 | **Redirect vers route POST-only.** `preview()` fait `redirectToRoute('app_mercuriale_import_confirm')` après un POST. Les redirections HTTP 302 deviennent des GET, mais `confirm` n'accepte que POST → **405 Method Not Allowed**. En pratique ce code n'est jamais atteint (le formulaire POST directement vers `confirm`), mais c'est du code mort/cassé. | Supprimer le bloc POST (lignes 284-293) dans `preview()` ou changer en forward interne. |
| 25 | 🟠 WARNING | `templates/base.html.twig` | 36 | URL hardcodée `href="/app/pending"` au lieu de `{{ path('app_pending') }}`. | Utiliser `{{ path('app_pending') }}`. |
| 26 | 🟠 WARNING | `src/Controller/Admin/DashboardController.php` | 83, 93 | `MenuItem::linkToUrl('/app/bl/upload')` et `linkToUrl('/app/mercuriale/import')` : URLs hardcodées. | Utiliser `MenuItem::linkToRoute('app_bl_upload')` et `linkToRoute('app_mercuriale_import')`. |
| 27 | 🟠 WARNING | `templates/admin/dashboard.html.twig` | 36, 63, 90 | Les 3 cards portail (BL, Mercuriale, Produits) visibles pour TOUS les admins sans vérification `is_granted`. Les CRUD correspondants exigent `ROLE_MANAGER` ou `ROLE_SUPER_ADMIN`. | Entourer chaque card de `{% if is_granted('ROLE_MANAGER') %}`. |

---

## 3. COHÉRENCE FRONTEND

### 3.1 Convention CSS

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 28 | 🟠 WARNING | `src/Controller/Admin/DashboardController.php` | 68 | **CSS inline dans le PHP** : `style="width: 200px; height: 45px; object-fit: cover; object-position: center;"` dans `setTitle()`. Violation de la convention CLAUDE.md. | Créer une classe `.ea-dashboard-logo` dans `admin.css`. |

**Aucune balise `<style>` ni attribut `style=""` trouvé dans les templates Twig.** Convention respectée côté templates.

### 3.2 Assets

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 29 | 🟠 WARNING | `templates/app/bon_livraison/extraction.html.twig` | 7 | **Chemin CSS hardcodé** `href="/css/extraction.css"` au lieu de `{{ asset('css/extraction.css') }}`. Casse le versioning d'assets et la compatibilité CDN. | Changer en `{{ asset('css/extraction.css') }}`. |
| 30 | 🟠 WARNING | `public/css/mercuriale-import.css` | — | Absent du pré-cache Service Worker (`sw.js` APP_SHELL_FILES). | Ajouter `'/css/mercuriale-import.css'` dans `APP_SHELL_FILES`. |
| 31 | 🟠 WARNING | `public/css/admin-dashboard.css` | — | Absent du pré-cache Service Worker. | Ajouter `'/css/admin-dashboard.css'` dans `APP_SHELL_FILES`. |
| 32 | 🟠 WARNING | `assets/styles/app.css` + `public/css/admin.css` | — | **Palette de couleurs dupliquée** : mêmes valeurs navy/coral/gold/cream définies dans Tailwind `@theme` ET en CSS custom properties dans `admin.css` (lignes 9-20). | Consolider en une seule source de vérité. |
| 33 | 🟡 INFO | `public/css/login.css` | 54 | Bouton login utilise `background: #0056d2` (bleu hors palette). Toutes les autres pages utilisent navy `#1e3a5f` ou coral `#f07560`. | Aligner avec la palette projet. |

### 3.3 Templates Twig

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 34 | 🟠 WARNING | `templates/app/bon_livraison/validate.html.twig` | — | **Template orphelin** : jamais rendu par aucun contrôleur. `BonLivraisonController::validate()` redirige vers `app_bl_extraction`. Contient du Bootstrap (classes `container`, `row`, `btn-primary`) alors que le reste du projet utilise Tailwind. | Supprimer ce template legacy. |

---

## 4. API PLATFORM

### 4.1 Ressources

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 35 | 🟠 WARNING | `config/packages/api_platform.yaml` | 2 | Titre par défaut **"Hello API Platform"** — exposé publiquement via `/api/docs`. Fuite d'information. | Changer en `title: Mercuriale.io API`. |
| 36 | 🟠 WARNING | — | — | **Zéro ressources API Platform** (`#[ApiResource]`). API Platform v4.2 installé mais non utilisé pour les ressources. Toute l'API est en contrôleurs custom. Le bundle ajoute de la surface d'attaque (`/api/docs`, routes auto) sans valeur. | Soit ajouter `#[ApiResource]` avec `security` sur les entités, soit supprimer `api-platform/core` de `composer.json`. |
| 37 | 🟠 WARNING | `config/packages/api_platform.yaml` | — | Pas de `defaults.security` global. Si `#[ApiResource]` est ajouté à une entité, elle sera publique par défaut. | Ajouter `defaults: security: "is_granted('ROLE_USER')"`. |

### 4.2 Protection de sérialisation

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 38 | 🔴 CRITIQUE | `src/Entity/Utilisateur.php` | 43 | **`$password` sans protection de sérialisation.** Pas de `#[Ignore]`, `#[Groups]` ni `#[ApiProperty(readable: false)]`. Si Symfony serializer est utilisé sur un User (`$this->json($user)`), le hash du mot de passe serait retourné. | Ajouter `#[Ignore]` sur `$password`. |
| 39 | 🔴 CRITIQUE | `src/Entity/PushSubscription.php` | 32-35 | **Secrets push non protégés** : `$p256dhKey` et `$authToken` sans `#[Ignore]`. Secrets cryptographiques pour Web Push. | Ajouter `#[Ignore]` sur les deux propriétés. |
| 40 | 🟠 WARNING | `src/Entity/AuditLog.php` | 39, 42 | `$ipAddress` et `$changes` sans protection. PII et données internes. | Ajouter `#[Ignore]` ou `#[Groups]`. |
| 41 | 🟠 WARNING | `src/Entity/LoginLog.php` | 33-36 | `$ipAddress` et `$userAgent` sans protection. PII. | Ajouter `#[Ignore]` ou `#[Groups]`. |
| 42 | 🟠 WARNING | `src/Entity/BonLivraison.php` | 61-62 | `$donneesBrutes` (JSON OCR brut) sans protection de sérialisation. | Ajouter `#[Ignore]`. |

### 4.3 Filtres & Pagination

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 43 | 🟠 WARNING | `src/Controller/Api/ReferentielController.php` | 24-60 | **`/api/referentiels/offline`** retourne TOUS les fournisseurs actifs sans limite/pagination. Organisation avec milliers de fournisseurs → réponse énorme. | Ajouter `setMaxResults()` ou implémenter la pagination. |
| 44 | 🟡 INFO | `src/Controller/Api/BonLivraisonReadController.php` | 51 | Pagination manuelle avec `$limit = min(..., 200)`. Maximum 200 résultats. | RAS — bonne pratique. |

---

## 5. DOCTRINE & BASE DE DONNÉES

### 5.1 Index manquants

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 45 | 🟠 WARNING | `src/Entity/BonLivraison.php` | 41-43 | Pas d'index sur `numero_bl`. Colonne affichée dans les listes et potentiellement recherchée. | Ajouter `#[ORM\Index(columns: ['numero_bl'], name: 'idx_bl_numero')]`. |
| 46 | 🟠 WARNING | `src/Entity/AlerteControle.php` | 55-56 | Pas d'index sur `created_at`. Alertes triées/filtrées par date. | Ajouter index. |
| 47 | 🟡 INFO | `src/Entity/Fournisseur.php` | 62 | Pas d'index sur `actif`. | Ajouter index composé `(actif, nom)`. |
| 48 | 🟡 INFO | `src/Entity/Produit.php` | 45 | Pas d'index sur `actif`. | Ajouter index. |
| 49 | 🟡 INFO | `src/Entity/ProduitFournisseur.php` | 58 | Pas d'index sur `actif`. | Ajouter index. |

### 5.2 Transactions

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 50 | 🔴 CRITIQUE | `src/Service/Controle/ControleService.php` | 34-60 | **`controlerBonLivraison()` sans transaction.** Supprime alertes, crée des AlerteControle, modifie statuts lignes et BL, flush unique. Si le flush échoue, état mémoire incohérent avec la BDD. Chemin critique : **imputation + contrôle**. | Envelopper dans `beginTransaction()`/`commit()`/`rollback()`. |
| 51 | 🔴 CRITIQUE | `src/Service/Ocr/BonLivraisonExtractorService.php` | 93-181 | **`extract()` sans transaction.** Crée N lignes BL + met à jour BL + met à jour fournisseur, flush unique. Chemin critique : **upload BL + création lignes**. | Envelopper lignes 127-146 dans une transaction explicite. |
| 52 | 🟠 WARNING | `src/Controller/App/BonLivraisonController.php` | 237-285 | **`valider()` : 2 flush sans transaction.** `controlerBonLivraison()` flush, puis mise à jour statut BL + flush. Si le 2e flush échoue, contrôle commité mais statut non mis à jour. | Transaction unique englobante. |
| 53 | 🟠 WARNING | `src/Controller/App/BonLivraisonController.php` | 328-399 | **`corrigerLigne()` : 2 flush sans transaction.** Flush correction ligne, puis `controlerBonLivraison()` flush. | Transaction unique. |
| 54 | 🟠 WARNING | `src/Controller/App/BonLivraisonController.php` | 287-326 | **`rejeter()` : supprime le fichier AVANT le flush.** Si le flush échoue, le fichier est supprimé du disque mais le record BDD subsiste (orphelin sans fichier). | Supprimer le fichier APRÈS le flush réussi. |
| 55 | 🟡 INFO | `src/Service/Import/MercurialeBulkImporter.php` | 317-498 | `execute()` utilise transaction explicite avec `beginTransaction()`/`commit()`/`rollback()` + reset EntityManager. | **Exemplaire** — modèle à suivre pour le reste du code. |

### 5.3 Relations — onDelete manquants

| # | Sévérité | Fichier | Ligne | Relation | Correction suggérée |
|---|----------|---------|-------|----------|---------------------|
| 56 | 🔴 CRITIQUE | `src/Entity/OrganisationFournisseur.php` | 28, 33 | **Mapping/BDD désynchronisés.** Entity : pas de `onDelete` (→ RESTRICT implicite). Migration (Version20260204120000:41-42) : `ON DELETE CASCADE`. `doctrine:schema:update` produirait des ALTER destructifs. | Ajouter `onDelete: 'CASCADE'` aux deux JoinColumn pour synchroniser avec la BDD. |
| 57 | 🟠 WARNING | `src/Entity/Etablissement.php` | 28 | `Organisation` → pas de `onDelete`, `nullable: false` | Ajouter `onDelete: 'CASCADE'` (cohérent avec `orphanRemoval: true` sur Organisation). |
| 58 | 🟠 WARNING | `src/Entity/Utilisateur.php` | 32 | `Organisation` → pas de `onDelete`, `nullable: false` | Ajouter `onDelete: 'CASCADE'`. |
| 59 | 🟠 WARNING | `src/Entity/UtilisateurEtablissement.php` | 26, 31 | `Utilisateur` et `Etablissement` → pas de `onDelete` | Ajouter `onDelete: 'CASCADE'` sur les deux. |
| 60 | 🟠 WARNING | `src/Entity/BonLivraison.php` | 33, 38 | `Etablissement` (non nullable) et `Fournisseur` (nullable) → pas de `onDelete` | Ajouter `onDelete: 'RESTRICT'` (Etablissement) et `onDelete: 'SET NULL'` (Fournisseur). |
| 61 | 🟠 WARNING | `src/Entity/ProduitFournisseur.php` | 35, 50 | `Fournisseur` et `Unite` → pas de `onDelete` | Ajouter `onDelete: 'CASCADE'` (Fournisseur) et `onDelete: 'RESTRICT'` (Unite). |
| 62 | 🟠 WARNING | `src/Entity/Mercuriale.php` | 29 | `ProduitFournisseur` → pas de `onDelete`, `nullable: false` | Ajouter `onDelete: 'CASCADE'`. |
| 63 | 🟠 WARNING | `src/Entity/MercurialeImport.php` | 32, 42 | `Fournisseur` et `Utilisateur` → pas de `onDelete` | Ajouter `onDelete: 'CASCADE'` (données temporaires). |
| 64 | 🟠 WARNING | `src/Entity/Produit.php` | 41 | `Unite` (uniteBase) → pas de `onDelete` | Ajouter `onDelete: 'RESTRICT'`. |
| 65 | 🟠 WARNING | `src/Entity/LigneBonLivraison.php` | 54 | `Unite` → pas de `onDelete`, `nullable: false` | Ajouter `onDelete: 'RESTRICT'`. |

### 5.4 Cascades dangereuses

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 66 | 🟠 WARNING | `src/Entity/Organisation.php` | 37 | `orphanRemoval: true` sur `etablissements`. Retirer un Etablissement de la collection **supprime l'Etablissement et tous ses BL**. Très destructif pour les données métier. | Envisager un soft-delete ou supprimer `orphanRemoval` et gérer la suppression explicitement. |
| 67 | 🟠 WARNING | `src/Entity/Organisation.php` | 41 | `orphanRemoval: true` sur `utilisateurs`. Retirer un utilisateur de la collection le supprime avec tout son historique. | Envisager un soft-delete (`actif=false`) pour les utilisateurs avec audit trail. |

---

## 6. QUALITÉ CODE

### 6.1 Code mort

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 68 | 🟠 WARNING | `templates/app/bon_livraison/validate.html.twig` | — | Template orphelin jamais rendu (Bootstrap dans un projet Tailwind). | Supprimer. |
| 69 | 🟡 INFO | `src/ApiResource/` | — | Dossier vide contenant seulement `.gitignore`. | Supprimer ou implémenter. |
| 70 | 🟡 INFO | `src/Controller/App/MercurialeImportController.php` | 284-293 | Bloc POST mort dans `preview()` qui redirigerait vers une route POST-only (→ 405). | Supprimer ce bloc de code. |

### 6.2 Logs & Erreurs

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 71 | 🟡 INFO | `src/Service/Ocr/BonLivraisonExtractorService.php` | 416 | `catch (\Exception)` vide — exception silencieusement ignorée. | Ajouter `$this->logger->debug()`. |
| 72 | 🟡 INFO | `src/Service/Import/MercurialeBulkImporter.php` | 452-498 | Gestion d'erreur exemplaire : rollback, reset EntityManager, sauvegarde statut FAILED. | RAS — modèle à suivre. |

### 6.3 Performances

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 73 | 🟠 WARNING | `src/Controller/Api/BonLivraisonReadController.php` | 125-174 | **Risque N+1** : sérialisation manuelle avec boucles sur `$bl->getLignes()` et `$ligne->getAlertes()`. | Vérifier que `findValidatedForUser` utilise `JOIN FETCH` pour lignes et alertes. |
| 74 | 🟡 INFO | `src/Service/Controle/ControleService.php` | 40 | Itération sur `$bl->getLignes()` avec lazy-loading sur `getProduitFournisseur()`, `getAlertes()`. | Optimiser avec un DQL `JOIN FETCH` si beaucoup de lignes. |

### 6.4 Divers

| # | Sévérité | Fichier | Ligne | Description | Correction suggérée |
|---|----------|---------|-------|-------------|---------------------|
| 75 | 🟠 WARNING | `.env` | 37 | `DATABASE_URL` par défaut pointe vers **MySQL** (`mysql://...`) mais le projet utilise vraisemblablement PostgreSQL (config doctrine `identity_generation_preferences: PostgreSQLPlatform`). | Aligner le template `.env` avec la BDD réelle. |
| 76 | 🟡 INFO | `config/packages/api_platform.yaml` | 3 | Version API par défaut `1.0.0`. | Mettre à jour avec le versioning réel. |

---

## Résumé

| Sévérité | Nombre |
|----------|--------|
| 🔴 CRITIQUE | **10** |
| 🟠 WARNING | **30** |
| 🟡 INFO | **21** |
| **Total** | **61** |

### Détail des CRITIQUES

| # | Section | Description courte |
|---|---------|-------------------|
| 1 | Sécurité | IDOR PushController::unsubscribe |
| 2 | Sécurité | IDOR PushController::subscribe |
| 9 | Sécurité | Validation manquante corrigerLigne (données financières) |
| 12 | Sécurité | CSRF manquant corrigerLigne |
| 24 | Routes | Redirect vers route POST-only (405) |
| 38 | API | `$password` Utilisateur sans protection sérialisation |
| 39 | API | Secrets push `$p256dhKey`/`$authToken` exposables |
| 50 | Doctrine | ControleService sans transaction (chemin critique) |
| 51 | Doctrine | BonLivraisonExtractorService sans transaction (chemin critique) |
| 56 | Doctrine | OrganisationFournisseur mapping/BDD désynchronisés |

---

## Actions prioritaires

### P0 — Failles de sécurité (à corriger immédiatement)

1. **IDOR PushController** (#1, #2) — Vérifier l'appartenance user avant toute modification de subscription push.

2. **Validation + CSRF `corrigerLigne`** (#9, #12) — Valider les valeurs numériques et ajouter le token CSRF.

3. **Protection sérialisation** (#38, #39) — Ajouter `#[Ignore]` sur `$password`, `$p256dhKey`, `$authToken`.

### P1 — Intégrité des données (à corriger rapidement)

4. **Transactions manquantes** (#50, #51, #52, #53, #54) — Envelopper les opérations critiques dans des transactions explicites.

5. **Sync mapping/BDD OrganisationFournisseur** (#56) — Aligner l'entité avec le schéma BDD réel.

6. **Relations sans onDelete** (#57-65) — Ajouter les `onDelete` + créer une migration.

### P2 — Sécurité avancée

7. **CSP sans unsafe-inline** (#20) — Migrer vers nonces CSP.

8. **Contrôle d'accès CRUD Admin** (#4, #5) — Ajouter `#[IsGranted]` sur les CRUD controllers.

### P3 — Qualité & Frontend

9. **Nettoyage code mort** (#34, #68, #69, #70) — Supprimer templates/dossiers/code mort.

10. **Assets & SW** (#29, #30, #31, #32) — Corriger chemins hardcodés, pré-cache SW, palette dupliquée.

---

## Points forts du projet

- **Voters Symfony** systématiquement utilisés pour la vérification d'accès IDOR
- **Rate limiting** complet sur tous les endpoints sensibles
- **Upload sécurisé** avec validation MIME + magic bytes + détection contenu suspect
- **Import CSV/Excel** protégé contre l'injection de formules + sanitization XSS
- **Refresh tokens** single-use, HttpOnly, Secure, SameSite=Strict
- **Tokens révocables** avec listener de blocage
- **Transactions explicites** sur l'import mercuriale en masse (MercurialeBulkImporter)
- **Logging** systématique des opérations critiques (OCR, upload, import)
- **Index Doctrine** bien placés sur les colonnes fréquemment requêtées (BL, Mercuriale)
- **Convention CSS** respectée dans tous les templates Twig (zéro violation)
- **CSRF** vérifié sur toutes les actions POST formulaire (sauf corrigerLigne)
- **Validation entités** complète avec Assert (NotBlank, Length, Positive, Email, Regex)

---

## Estimation des corrections

| Priorité | Actions | Effort estimé |
|----------|---------|---------------|
| P0 — Failles sécurité | IDOR Push, validation/CSRF corrigerLigne, #[Ignore] | ~2h |
| P1 — Intégrité données | Transactions, sync onDelete, migration | ~4h |
| P2 — Sécurité avancée | CSP nonces, #[IsGranted] CRUD | ~3h |
| P3 — Qualité & Frontend | Code mort, assets, SW cache | ~2h |
| **Total** | | **~11h** |
