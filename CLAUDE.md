# CLAUDE.md — Mercuriale.io

> Ce fichier est lu automatiquement par Claude Code à chaque session.
> Dernière mise à jour : 26/02/2026

---

## Projet

**Mercuriale.io** est un SaaS PWA destiné aux restaurateurs pour gérer leurs mercuriales fournisseur (catalogues de prix) et maîtriser leur food cost. Le cœur du produit repose sur l'upload de bordereaux de livraison (BL), leur extraction automatique par OCR via Claude Vision API, et la vérification des prix/quantités livrés par rapport à la mercuriale de référence (seuil d'alerte à 5%).

### Client pilote

- **La Guinguette du Château** — SAS à Abzac (33230), code client 379212
- Fournisseurs testés : **TerreAzur** (groupe Pomona — fruits, légumes, marée), **Le Bihan TMEG** (boissons, alcools)
- Données de consommation disponibles depuis l'été 2025

---

## Stack technique

| Composant       | Technologie |
|-----------------|------------|
| Backend         | PHP 8.3, Symfony 7, API Platform, Doctrine ORM |
| Base de données | PostgreSQL |
| Auth            | LexikJWTAuthenticationBundle (RS256), gesdinet/jwt-refresh-token-bundle, refresh tokens en BDD avec rotation et révocation. Interface `AuthenticationProviderInterface` découplée pour préparer la migration Keycloak. |
| OCR             | Claude Vision API via Symfony Messenger (async) |
| PWA             | Workbox (service worker), Dexie.js (IndexedDB offline), web-push-php |
| Frontend        | Twig + JS vanilla |
| Infra           | OVH mutualisé, HTTPS forcé via `.htaccess`, `chmod 600` sur clé JWT privée |

---

## Structure des entités principales

> ⚠️ Claude Code : exécuter `php bin/console doctrine:mapping:info` pour obtenir la liste exacte et à jour des entités. Ce qui suit est la structure connue — vérifier avant de modifier.

### User
- id, email, password, roles (ROLE_ADMIN, ROLE_GERANT, ROLE_CUISINIER)
- Lien vers Restaurant/Établissement

### Restaurant (Établissement)
- id, name, address, siret, phone, email
- Entité pivot pour l'IDOR : toutes les données métier sont rattachées à un restaurant

### Supplier (Fournisseur)
- id, name, code, email, phone, address
- ManyToOne → Restaurant
- Fournisseurs connus : TerreAzur (code fournisseur 3010614324970), Le Bihan TMEG

### Product (Produit)
- id, code (code article fournisseur), designation, unit (KG, L, pièce, COL, BOT, BQT, SAC, FUT, CAR, PU, UNI, FLT…), vat
- ManyToOne → Supplier
- ManyToOne → Restaurant

### PriceList / Mercuriale (Catalogue de prix)
- Entrées de prix par produit + fournisseur
- Prix unitaire de référence, date de validité
- Import bulk Excel/CSV (MERC-3) : auto-mapping colonnes, max 5000 lignes

### DeliveryReceipt (Bordereau de Livraison — BL)
- id, reference (numéro BL), deliveryDate, supplier
- status (en attente, vérifié, écart détecté…)
- Fichier image uploadé, traité par OCR async
- ManyToOne → Supplier, ManyToOne → Restaurant

### DeliveryReceiptLine (Ligne de BL)
- id, productCode, designation, origin (FR, ES, MA, BR, PE…)
- quantityDelivered, uniteLivraison (COL, BOT, BQT, SAC, FUT, CAR, PU, UNI, FLT, KG…)
- unitPrice, totalAmount
- Champs de vérification vs mercuriale : prix mercuriale, écart %, statut vérification
- ManyToOne → DeliveryReceipt, ManyToOne → Product (nullable)

### CreditNote (Avoir fournisseur — V2, MERC-23)
- id (UUID), reference (unique, préfixe AV-), status (draft, validated, applied, cancelled)
- reason (enum: return, billing_error, quantity_gap, commercial)
- amountExclTax, vatAmount, amountInclTax, comment, issuedAt
- validatedAt, appliedAt, createdBy, validatedBy
- ManyToOne → Supplier, ManyToOne → DeliveryReceipt (nullable)

### CreditNoteLine
- id (UUID), quantity (decimal 10,3), unitPrice (decimal 10,4), lineAmount (decimal 10,2)
- ManyToOne → CreditNote, ManyToOne → Product

---

## Rôles et permissions

| Rôle             | Accès |
|------------------|-------|
| `ROLE_ADMIN`     | Tout : gestion utilisateurs, paramètres établissement, CRUD complet, validation/imputation avoirs, suppression BL |
| `ROLE_GERANT`    | Upload BL, validation BL, création/validation avoirs, gestion mercuriale, alertes, modification prix |
| `ROLE_CUISINIER` | Consultation uniquement : mercuriale, BL, alertes. Profil personnel. |

Contrôle d'accès via **Voters Symfony** (MERC-11). Chaque Voter vérifie le rôle ET l'appartenance à l'établissement.

---

## Logique métier clé

### Vérification BL vs Mercuriale (MERC-2)
1. Un BL est uploadé (image JPEG/PDF)
2. Symfony Messenger dispatche le traitement OCR async
3. Claude Vision API extrait : fournisseur, numéro BL, date, lignes (code, désignation, origine, qté, unité, prix)
4. Post-traitement : matching des produits extraits avec la mercuriale de référence
5. Calcul de l'écart % : `(prix_BL - prix_mercuriale) / prix_mercuriale × 100`
6. Si écart > 5% → alerte visuelle (ligne surlignée orange/rouge)

> ⚠️ Bug connu : le post-traitement OCR peut matcher avec le mauvais produit mercuriale (fuzzy match trop agressif). Vérifier la réponse JSON brute de Claude Vision AVANT le mapping. Logguer la réponse brute dans le handler Messenger.

### Import mercuriale (MERC-3)
- Upload Excel/CSV
- Auto-mapping des colonnes
- Max 5000 lignes par import
- Validation MIME + magic bytes (pas d'exécution de formules Excel : =CMD, =HYPERLINK…)

### Avoirs fournisseur (MERC-23, V2)
- Cycle de vie : brouillon → validé → imputé (ou annulé)
- Imputation atomique via transaction Doctrine : mise à jour avoir + recalcul food cost
- Event subscriber Doctrine pour le recalcul food cost à l'imputation

---

## Commandes utiles

```bash
# Serveur de dev
symfony server:start

# Base de données
php bin/console doctrine:schema:validate
php bin/console doctrine:schema:update --dump-sql
php bin/console make:migration
php bin/console doctrine:migrations:migrate

# Cache
php bin/console cache:clear

# Messenger (worker OCR)
php bin/console messenger:consume async -vv

# Routes
php bin/console debug:router
php bin/console debug:router --show-controllers

# Sécurité
php bin/console debug:firewall
php bin/console debug:voter

# Entités
php bin/console doctrine:mapping:info

# Linter
php bin/console lint:twig templates/
php bin/console lint:yaml config/
```

---

## Conventions de développement

### CSS — RÈGLE ABSOLUE
- CSS TOUJOURS dans des fichiers `.css` séparés dans le dossier approprié
- JAMAIS de CSS inline (`style="..."`) dans les templates Twig
- JAMAIS de balise `<style>` dans les templates Twig
- Variables CSS pour les couleurs et espacements récurrents
- Mobile-first avec breakpoints : 480px, 768px, 1024px
- Touch targets minimum 44×44px (contexte restauration : mains occupées, écrans gras)

### Commits
- Un commit par axe fonctionnel
- Message descriptif avec préfixe conventionnel :
  - `feat(scope): description` — nouvelle fonctionnalité
  - `fix(scope): description` — correction de bug
  - `refactor(scope): description` — refactoring sans changement fonctionnel
  - `security(scope): description` — correctif de sécurité

### Workflow
Sprint planning → human review → validation → prompt Claude Code → review → implémentation

---

## Sécurité — Checklist obligatoire

Avant chaque commit, vérifier :

### IDOR
- [ ] Chaque action (show, edit, delete) vérifie que l'entité appartient à l'établissement de l'utilisateur courant
- [ ] Les requêtes Doctrine filtrent par restaurant
- [ ] Les endpoints API Platform ont `security` et `securityPostDenormalize`

### Contrôle d'accès
- [ ] Contrôleurs protégés par `#[IsGranted]` ou Voter
- [ ] Actions sensibles (validation, imputation, suppression) réservées aux bons rôles

### Validation
- [ ] Contraintes `#[Assert\...]` sur toutes les propriétés d'entité
- [ ] Validation MIME + magic bytes sur les uploads (pas juste l'extension)
- [ ] Pas d'exécution de formules dans les imports Excel/CSV
- [ ] Pas de requêtes DQL/SQL construites par concaténation

### Infra
- [ ] CSRF activé sur tous les formulaires POST
- [ ] Rate limiting sur les endpoints sensibles (login, upload, création avoirs, envoi email)
- [ ] HTTPS forcé (.htaccess)
- [ ] Clé JWT privée en chmod 600
- [ ] Transactions Doctrine pour les opérations multi-entités

### Audit trail
- [ ] `createdBy`, `validatedBy`, timestamps sur les changements de statut
- [ ] Opérations critiques loguées (upload OCR, import, changements de statut)

---

## Gestion de projet

- **Outil** : Linear — Team "Mercuriale", préfixe `MERC`
- **MCP** : connecté via https://mcp.linear.app/mcp
- **Cycles** : Sprints de 2 semaines, démarrage lundi, auto-rollover
- **Labels** : `security`, `pwa`, `feature`, `ai`, `billing`, `bug`, `ui/ux`

---

## Roadmap

### V1 — MVP (gratuit)

| Ticket    | Fonctionnalité | Statut |
|-----------|---------------|--------|
| MERC-1    | Upload BL + OCR Claude Vision | ✅ Done |
| MERC-2    | Vérification BL vs mercuriale (seuil 5%) | ✅ Done |
| MERC-3    | Import mercuriale bulk (Excel/CSV) | ✅ Done |
| MERC-4    | PWA Sprint 1 (manifest, SW, offline, layout mobile) | ✅ Done |
| MERC-5    | Sprint Sécu Auth (refresh tokens, JWT, HTTPS, Keycloak) | 🔄 En cours |
| MERC-6→10 | PWA Sprints 2-6 (Dexie.js → Sync → Push → Offline → Safari) | 📋 Planifié |
| MERC-11   | Gestion rôles (Voters Symfony) | 📋 Planifié |
| MERC-12   | Landing page | 📋 Planifié |

### V2 — Payant (15€/mois via Stripe)

| Ticket  | Fonctionnalité |
|---------|---------------|
| MERC-13 | Dashboard analytics (food cost, évolution prix, top dépenses) |
| MERC-14 | Alertes prix (notification si hausse > seuil) |
| MERC-15 | Historique prix (courbe évolution par produit) |
| MERC-16 | Comparaison fournisseurs multi-catalogues |
| MERC-17 | Export comptable (Excel, PDF, Pennylane) |
| MERC-18 | Multi-établissements (consolidation groupe) |
| MERC-19 | Fiches techniques (coût recette = somme ingrédients) |
| MERC-20 | Prédictif stocks (projection saisonnière) |
| MERC-21 | Dashboard super admin (vue groupe) |
| MERC-22 | Billing Stripe (plans, webhooks, scopes JWT) |
| MERC-23 | Avoirs fournisseur (credit notes) |

### Bugs connus
- MERC-24 : 403 à l'upload import mercuriale en preprod (probablement lié aux changements MERC-5 sur security.yaml ou CSRF/JWT)
- OCR : post-traitement fuzzy match peut attribuer le mauvais produit mercuriale — vérifier la réponse brute Claude Vision avant mapping
- Scanner BL : diagnostic en cours (formulaire upload, consumer Messenger, feedback front)

---

## Fournisseurs — Format des BL

### TerreAzur (groupe Pomona)
- Numéro BL : format `78XXXXXXXX`
- Numéro commande Pomona : format `31XXXXXXXX`
- Tournée : format `337283XXX`
- Code fournisseur : 3010614324970
- Colonnes : Ligne, Article, Désignation, Qté livrée, Qté fact. UF, PU, Poids brut, MJ.DECOL, TVA, MT HT
- Unités : COL, BOT, BQT, SAC, KG, PU
- Récapitulatif en bas : Total colis, Poids total kg, Montant HT, TVA, Montant TVA, Net à payer

### Le Bihan TMEG
- Numéro BL : format `002XXXXX`
- Colonnes : Code, Désignation, Ref Client, Quantité Unité Cde, Quantité Unité Fac, Prix Unitaire, Montant, Dt Remise, Dt Droits, Consigne, Déconsigne, N° ACCISE, Vol Effectif, Alcool Pur, Poids Brut, Poids Net
- Spécificités : droits d'accise, consignes/déconsignes, taux alcool
- Multi-pages (page 1/2, 2/2)
- Récapitulatif : Total TTC, Consignes, Total Facture, Montant HT par taux TVA (5,50% et 20,00%)

---

## Outils métier associés

- **Silae** : paie
- **Loop GGID** : comptabilité
- **Facturation électronique** : en place

---

## Notes pour Claude Code

1. **Toujours exécuter `doctrine:schema:validate`** après modification d'une entité pour vérifier la synchro avec la BDD.
2. **Ne jamais modifier la clé JWT** sans vérifier les permissions fichier (chmod 600).
3. **Logguer la réponse brute Claude Vision** dans le handler Messenger avant tout post-traitement — c'est essentiel pour le debug OCR.
4. **Tester sur mobile** (viewport 375px) en plus du desktop — les utilisateurs cibles sont en cuisine avec un téléphone ou une tablette.
5. **Les imports Excel/CSV sont dangereux** : toujours valider MIME + magic bytes, ne jamais exécuter de formules, limiter à 5000 lignes.
6. **Transactions Doctrine obligatoires** pour : imputation avoirs + recalcul food cost, upload BL + création lignes, import mercuriale bulk.
7. **Vérifier l'IDOR sur CHAQUE endpoint** — c'est le risque #1 sur une app multi-tenant.