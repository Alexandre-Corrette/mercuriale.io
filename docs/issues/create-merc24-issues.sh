#!/bin/bash
# Script de création des issues GitHub pour MERC-24 (Intégration API B2Brouter)
# Usage: ./create-merc24-issues.sh
# Prérequis: gh CLI authentifié (gh auth login)

set -euo pipefail

REPO="Alexandre-Corrette/mercuriale.io"

echo "=== Création des issues MERC-24 — Intégration API B2Brouter ==="
echo ""

# ---------------------------------------------------------------
# MERC-24a — DTOs et interface PdpClientInterface
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): DTOs et interface PdpClientInterface [MERC-24a]" \
  --body "$(cat <<'EOF'
## Contexte

Première brique de l'intégration B2Brouter (PDP partenaire) pour la facturation électronique. Ce ticket pose les fondations : les DTOs et l'interface de découplage.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter

## Tâches

- [ ] Créer `src/Service/EInvoicing/InvoiceData.php` (DTO facture)
- [ ] Créer `src/Service/EInvoicing/InvoiceLineData.php` (DTO ligne de facture)
- [ ] Créer `src/Service/EInvoicing/PdpApiException.php` (exception métier)
- [ ] Créer `src/Service/EInvoicing/PdpClientInterface.php` avec les méthodes :
  - `registerCompany()` — crée un compte entreprise sur la PDP
  - `enableReception()` — active les transports de réception
  - `fetchPendingInvoices()` — récupère les factures reçues non acquittées
  - `getInvoiceWithLines()` — détail d'une facture avec ses lignes
  - `getOriginalDocument()` — télécharge le XML/PDF original
  - `acknowledgeInvoice()` — marque comme acquittée
  - `updateInvoiceStatus()` — met à jour le statut (accepted/refused/paid)
- [ ] Ajouter la méthode utilitaire `extractSirenFromFrenchVat(string $vatNumber): ?string`

## Critères d'acceptance

- L'interface est découplée de toute implémentation concrète (permet de changer de PDP)
- Les DTOs utilisent les `readonly` properties PHP 8.2
- `PdpApiException` étend `\RuntimeException`
- L'extraction SIREN fonctionne : `FR32823456789` → `823456789`

## Priorité

Haute — bloque les tickets suivants
EOF
)"
echo "✓ MERC-24a créée"

# ---------------------------------------------------------------
# MERC-24b — Implémentation B2BRouterPdpClient
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): implémentation B2BRouterPdpClient [MERC-24b]" \
  --body "$(cat <<'EOF'
## Contexte

Implémentation concrète de `PdpClientInterface` utilisant l'API B2Brouter. Ce client gère tous les appels HTTP vers la PDP.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24a (interface + DTOs)

## Tâches

- [ ] Créer `src/Service/EInvoicing/B2BRouterPdpClient.php` implémentant `PdpClientInterface`
- [ ] Implémenter les méthodes privées `request()` et `requestRaw()` :
  - Headers : `X-B2B-API-Key`, `X-B2B-API-Version`, `Accept`, `Content-Type`
  - Timeout : 10s pour JSON, 30s pour documents originaux
  - Gestion des codes HTTP (204 = vide, ≥400 = exception)
- [ ] Implémenter `registerCompany()` : `POST /accounts`
  - Payload : `account.country`, `tin_value`, `name`, `address`, `city`, `postalcode`, `email`
  - Options fixes : `rounding_method: half_up`, `round_before_sum: false`, `apply_taxes_per_line: false`
- [ ] Implémenter `enableReception()` : 2x `POST /accounts/{id}/transports`
  - Transport `b2brouter` (interne) + transport `peppol` (réseau international)
- [ ] Implémenter `fetchPendingInvoices()` : `GET /accounts/{id}/invoices?type=ReceivedInvoice`
- [ ] Implémenter `getInvoiceWithLines()` : `GET /invoices/{id}?include=lines`
- [ ] Implémenter `getOriginalDocument()` : `GET /invoices/{id}/as/original`
- [ ] Implémenter `acknowledgeInvoice()` : `POST /invoices/{id}/acknowledge`
- [ ] Implémenter `updateInvoiceStatus()` : `POST /invoices/{id}/mark_as`
- [ ] Implémenter `mapToInvoiceData()` : mapping réponse JSON → `InvoiceData`

## Mapping B2Brouter → InvoiceData

| Champ B2Brouter | Champ InvoiceData |
|---|---|
| `invoice.id` | `externalId` |
| `invoice.number` | `number` |
| `invoice.contact.name` | `supplierName` |
| `invoice.contact.tin_value` | `supplierTin` |
| `invoice.state` | `state` |
| `invoice.date` | `date` |
| `invoice.due_date` | `dueDate` |
| `invoice.subtotal` | `subtotal` |
| `invoice.taxes` | `taxes` |
| `invoice.total` | `total` |
| `invoice.currency` | `currency` |
| `invoice.lines[].description` | `InvoiceLineData.description` |
| `invoice.lines[].quantity` | `InvoiceLineData.quantity` |
| `invoice.lines[].price` | `InvoiceLineData.unitPrice` |
| `invoice.lines[].total_cost` | `InvoiceLineData.lineAmount` |

## Configuration Symfony

```yaml
# config/services.yaml
parameters:
    b2brouter_api_url: '%env(B2BROUTER_API_URL)%'
    b2brouter_api_key: '%env(B2BROUTER_API_KEY)%'
    b2brouter_api_version: '%env(B2BROUTER_API_VERSION)%'
```

```dotenv
# .env (staging par défaut)
B2BROUTER_API_URL=https://api-staging.b2brouter.net
B2BROUTER_API_KEY=your_api_key_here
B2BROUTER_API_VERSION=v1
```

## Sécurité

- API Key en variable d'environnement uniquement (jamais committée, jamais en BDD)
- Logger les appels API (méthode, endpoint, status code) mais JAMAIS le contenu des factures ni l'API key
- Staging vs Prod différenciés par `B2BROUTER_API_URL`

## Critères d'acceptance

- Le client utilise `HttpClientInterface` de Symfony (injection de dépendances)
- Les erreurs API lèvent `PdpApiException` avec le code HTTP
- Les timeouts sont respectés (10s JSON, 30s documents)
EOF
)"
echo "✓ MERC-24b créée"

# ---------------------------------------------------------------
# MERC-24c — Champs e-invoicing sur Establishment
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): champs e-invoicing sur l'entité Establishment [MERC-24c]" \
  --body "$(cat <<'EOF'
## Contexte

Ajout des champs nécessaires sur l'entité `Establishment` pour stocker les informations de liaison avec la PDP B2Brouter.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24a

## Tâches

- [ ] Ajouter les champs suivants sur `Establishment` :

```php
#[ORM\Column(type: 'string', length: 50, nullable: true)]
private ?string $pdpAccountId = null;    // ID B2Brouter (ex: "73509")

#[ORM\Column(type: 'boolean')]
private bool $eInvoicingEnabled = false;  // Réception activée ?

#[ORM\Column(type: 'datetime_immutable', nullable: true)]
private ?\DateTimeImmutable $eInvoicingEnabledAt = null;
```

- [ ] Ajouter les getters/setters correspondants
- [ ] Créer la migration Doctrine
- [ ] Exécuter la migration sur la base de dev/staging

## Critères d'acceptance

- `pdpAccountId` est nullable (l'inscription PDP est optionnelle)
- `eInvoicingEnabled` a une valeur par défaut `false`
- `eInvoicingEnabledAt` est renseigné automatiquement lors de l'activation
- La migration est réversible
EOF
)"
echo "✓ MERC-24c créée"

# ---------------------------------------------------------------
# MERC-24d — Onboarding PDP
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): onboarding PDP — inscription établissement sur B2Brouter [MERC-24d]" \
  --body "$(cat <<'EOF'
## Contexte

Créer le flow d'inscription d'un établissement sur la PDP B2Brouter. Activable depuis le profil de l'établissement (section "Facturation électronique").

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24a, MERC-24b, MERC-24c, MERC-25 (données SIREN)

## Séquence

```
Profil > Facturation électronique > "Activer la réception"
    │
    ▼
POST /accounts B2Brouter
    - tin_value = TVA intracommunautaire (FR + clé + SIREN)
    - name, address, city, postalcode = depuis données Establishment
    - country = "fr"
    → pdpAccountId stocké en BDD
    │
    ▼
POST /accounts/{id}/transports (b2brouter)
POST /accounts/{id}/transports (peppol)
    → eInvoicingEnabled = true
    → eInvoicingEnabledAt = now()
```

## Tâches

- [ ] Créer un service `EInvoicingOnboardingService` orchestrant l'inscription
- [ ] Implémenter l'action controller (ou EasyAdmin) pour le bouton "Activer"
- [ ] Valider que l'établissement a bien un numéro TVA intracommunautaire avant inscription
- [ ] Stocker `pdpAccountId` et passer `eInvoicingEnabled = true`
- [ ] Gérer les erreurs (TVA invalide, compte déjà existant, erreur API)
- [ ] Ajouter une section "Facturation électronique" dans le profil établissement
- [ ] Afficher le statut d'inscription (non inscrit / inscrit / actif)

## Critères d'acceptance

- L'inscription nécessite un numéro TVA intracommunautaire valide
- Les 2 transports (b2brouter + peppol) sont activés automatiquement
- Un message flash confirme l'activation
- En cas d'erreur API, le message est explicite et aucune donnée partielle n'est sauvée
- L'opération est idempotente (re-cliquer ne crée pas un doublon)
EOF
)"
echo "✓ MERC-24d créée"

# ---------------------------------------------------------------
# MERC-24e — Worker Messenger polling factures
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): worker Messenger — polling et import des factures [MERC-24e]" \
  --body "$(cat <<'EOF'
## Contexte

Mise en place du polling automatique des factures reçues via B2Brouter, import en base et acquittement.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24a, MERC-24b, MERC-24c

## Flow

```
Cron (toutes les 15 min)
    │
    ▼
app:fetch-invoices
    → Pour chaque Establishment avec eInvoicingEnabled = true
    → Dispatch FetchPendingInvoicesMessage
    │
    ▼
FetchPendingInvoicesHandler
    1. GET /accounts/{id}/invoices?type=ReceivedInvoice
    2. Pour chaque facture non encore importée (vérification externalId) :
       a. GET /invoices/{id}?include=lines  → détail + lignes
       b. GET /invoices/{id}/as/original    → XML/PDF archivé
       c. Création SupplierInvoice + SupplierInvoiceLines en BDD
       d. Auto-détection du Supplier via tin_value (extraction SIREN)
       e. Tentative de rapprochement avec un BL existant
       f. POST /invoices/{id}/acknowledge   → acquittement B2Brouter
```

## Tâches

- [ ] Créer `src/Message/FetchPendingInvoicesMessage.php`
- [ ] Créer `src/Message/Handler/FetchPendingInvoicesHandler.php` (`#[AsMessageHandler]`)
- [ ] Créer `src/Command/FetchInvoicesCommand.php` (`app:fetch-invoices`)
  - Itère sur les Establishment avec `eInvoicingEnabled = true`
  - Dispatch un `FetchPendingInvoicesMessage` par établissement
- [ ] Implémenter le handler :
  - Vérification doublon par `externalId`
  - Récupération détaillée avec lignes
  - Téléchargement et archivage du document original
  - Mapping `InvoiceData` → entité `SupplierInvoice` + `SupplierInvoiceLine`
  - Auto-détection `Supplier` par extraction SIREN du `tin_value` fournisseur
  - Acquittement sur B2Brouter après import réussi
- [ ] Configurer Messenger transport async (Doctrine)
- [ ] Configurer retry : 3 tentatives, backoff exponentiel (1s, 2s, 4s)

## Mapping B2Brouter → SupplierInvoice

| B2Brouter | SupplierInvoice |
|---|---|
| `invoice.id` | `externalId` |
| `invoice.number` | `reference` |
| `invoice.contact.name` | Auto-match → `Supplier.name` |
| `invoice.contact.tin_value` | Auto-match → `Supplier.siren` |
| `invoice.state` (new/received) | `status` = received |
| `invoice.state` (accepted) | `status` = matched |
| `invoice.state` (refused) | `status` = disputed |
| `invoice.date` | `issueDate` |
| `invoice.due_date` | `dueDate` |
| `invoice.subtotal` | `amountExclTax` |
| `invoice.taxes[].amount` (sum) | `vatAmount` |
| `invoice.total` | `amountInclTax` |
| `invoice.is_credit_note = true` | → Créer `CreditNote` (MERC-23) |

## Cron

```
*/15 * * * * php /path/to/bin/console app:fetch-invoices
```

## Critères d'acceptance

- Les factures déjà importées ne sont pas re-importées (unicité `externalId`)
- Le document original (XML/PDF) est archivé sur le filesystem
- L'acquittement n'est envoyé qu'après un import réussi en BDD
- Les erreurs API ne bloquent pas le traitement des autres factures
- Les avoirs (`is_credit_note`) sont traités séparément (CreditNote)
- Le rate limiting est respecté : 1 polling max par établissement toutes les 15 min
EOF
)"
echo "✓ MERC-24e créée"

# ---------------------------------------------------------------
# MERC-24f — Rapprochement automatique facture / BL
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): rapprochement automatique facture ↔ BL [MERC-24f]" \
  --body "$(cat <<'EOF'
## Contexte

Service de rapprochement automatique entre les factures importées depuis B2Brouter et les bons de livraison (BL) existants dans Mercuriale.io. Détection des écarts de prix/quantité.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24e (import factures), entités SupplierInvoice et DeliveryReceipt existantes

## Tâches

- [ ] Créer `src/Service/EInvoicing/InvoiceMatchingService.php`
- [ ] Implémenter `matchInvoiceWithDeliveryReceipt(SupplierInvoice $invoice)`
  - Recherche BL candidats par fournisseur (SIREN) et période (date facture ± N jours)
  - Comparaison des lignes : désignation, quantité, prix unitaire
  - Calcul des écarts et stockage
- [ ] Définir les statuts de rapprochement :
  - `matched` — correspondance exacte
  - `partial_match` — correspondance partielle (écarts détectés)
  - `unmatched` — aucun BL correspondant trouvé
- [ ] Mettre à jour le statut de la facture via `updateInvoiceStatus()` sur B2Brouter
- [ ] Créer une vue/notification pour les écarts détectés (alerter le restaurateur)

## Critères d'acceptance

- Le rapprochement est tenté automatiquement à l'import
- Les écarts de prix et de quantité sont identifiés et stockés
- Le restaurateur est notifié des factures avec écarts
- Les factures sans BL correspondant sont marquées `unmatched` (traitement manuel)
EOF
)"
echo "✓ MERC-24f créée"

# ---------------------------------------------------------------
# MERC-24g — Gestion des statuts facture
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "feat(einvoicing): gestion des statuts facture (accepter/refuser/payer) [MERC-24g]" \
  --body "$(cat <<'EOF'
## Contexte

Permettre au restaurateur de gérer le cycle de vie des factures reçues : accepter, refuser (avec motif), marquer comme payée. Les actions sont synchronisées avec B2Brouter.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24e (import factures), MERC-24f (rapprochement)

## Tâches

- [ ] Ajouter les actions dans le CRUD SupplierInvoice (EasyAdmin ou contrôleur dédié) :
  - **Accepter** → `POST /invoices/{id}/mark_as` avec `state: accepted`
  - **Refuser** → `POST /invoices/{id}/mark_as` avec `state: refused` + `reason`
  - **Marquer payée** → `POST /invoices/{id}/mark_as` avec `state: paid`
- [ ] Formulaire de refus avec champ "motif" obligatoire
- [ ] Synchroniser le statut local avec B2Brouter (appel API `updateInvoiceStatus`)
- [ ] Logger les changements de statut (qui, quand, quel statut)
- [ ] Afficher l'historique des statuts sur la fiche facture

## Mapping des statuts

| Action utilisateur | Statut local | Statut B2Brouter |
|---|---|---|
| Import automatique | `received` | `new` / `received` |
| Rapprochement OK | `matched` | `accepted` |
| Accepter manuellement | `accepted` | `accepted` |
| Refuser | `refused` | `refused` |
| Marquer payée | `paid` | `paid` |

## Critères d'acceptance

- Chaque changement de statut est synchronisé avec B2Brouter en temps réel
- Le refus exige un motif
- L'historique des statuts est traçable (audit)
- Les erreurs API n'empêchent pas la mise à jour locale (retry asynchrone si nécessaire)
EOF
)"
echo "✓ MERC-24g créée"

# ---------------------------------------------------------------
# MERC-24h — Tests unitaires et d'intégration
# ---------------------------------------------------------------
gh issue create --repo "$REPO" \
  --label "enhancement" \
  --title "test(einvoicing): tests unitaires et d'intégration B2Brouter [MERC-24h]" \
  --body "$(cat <<'EOF'
## Contexte

Suite de tests pour valider l'intégration B2Brouter de bout en bout. Tests unitaires pour le client API et les services, tests d'intégration sur l'environnement staging.

**Réf. CDC** : Addendum MERC-24 — Intégration API B2Brouter
**Dépend de** : MERC-24a à MERC-24g

## Tests unitaires

- [ ] `B2BRouterPdpClientTest` :
  - Mock `HttpClientInterface` pour chaque méthode
  - Vérifier les headers envoyés (`X-B2B-API-Key`, `X-B2B-API-Version`)
  - Tester le mapping JSON → `InvoiceData`
  - Tester la gestion des erreurs (400, 401, 404, 500) → `PdpApiException`
  - Tester le cas 204 (réponse vide)
- [ ] `InvoiceMatchingServiceTest` :
  - Rapprochement exact → `matched`
  - Écart de quantité → `partial_match`
  - Aucun BL correspondant → `unmatched`
- [ ] `extractSirenFromFrenchVat()` :
  - `FR32823456789` → `823456789`
  - `FR00552044992` → `552044992`
  - `DE123456789` → `null` (pas français)
  - `INVALID` → `null`

## Tests d'intégration staging

- [ ] Scénario complet avec La Guinguette du Château :
  1. Créer le compte sur B2Brouter staging
  2. Activer les transports (b2brouter + peppol)
  3. Émettre une facture fictive depuis un fournisseur staging
  4. Vérifier la réception via polling
  5. Vérifier le parsing des lignes de facture
  6. Tester le rapprochement avec un BL existant
  7. Tester accepter / refuser / acquitter
  8. Vérifier le téléchargement du document original

## Données de test

| Champ | Valeur |
|---|---|
| Entreprise test | SAS LA GUINGUETTE DU CHATEAU |
| Adresse | LIEU DIT LAUBRADE, 33230 ABZAC |
| Fournisseur test | TerreAzur (Pomona) — SIREN 552044992 |

## Critères d'acceptance

- Couverture tests unitaires ≥ 80% sur les classes EInvoicing
- Les tests unitaires tournent sans appel réseau (mocks)
- Les tests d'intégration staging sont documentés et reproductibles
- Un scénario end-to-end complet passe sur staging
EOF
)"
echo "✓ MERC-24h créée"

echo ""
echo "=== 8 issues MERC-24 créées avec succès ==="
