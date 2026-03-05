# Todo — Mercuriale.io

**Dernière mise à jour** : 05/03/2026

---

## Demain — Module Nouveau Fournisseur + Contacts

> Branch : `feature/merc-92-nouveau-fournisseur-contacts`
> Ordre d'exécution : 92 → 93 → 94 → 95

| # | Issue | Titre | Priorité | Bloqué par |
|---|-------|-------|----------|------------|
| 1 | MERC-92 | Création fournisseur via SIREN + formulaire manuel | Medium | — |
| 2 | MERC-93 | Entité SupplierContact + section contacts fiche fournisseur | Medium | MERC-92 |
| 3 | MERC-94 | Card Contacts fournisseur + rédaction email in-app | Medium | MERC-93 |
| 4 | MERC-95 | Fiche fournisseur : suppression liste produits + bouton Commander | Medium | — |

### Détail par issue

**MERC-92** — Création fournisseur via SIREN
- Lookup API SIRENE (INSEE) : autocomplete SIREN → nom, adresse, SIRET
- Formulaire manuel en fallback si API down ou fournisseur étranger
- Nouveau `FournisseurCreateController` + `FournisseurCreateType`
- Tile "Nouveau fournisseur" dans hub fournisseurs

**MERC-93** — Entité SupplierContact
- Entity `SupplierContact` (UUID) : nom, role, email, telephone, ManyToOne Fournisseur
- Section "Contacts" dans la fiche fournisseur show
- CRUD inline (ajout/suppression contacts)
- Voter : vérifier appartenance à l'organisation

**MERC-94** — Card Contacts + email in-app
- Entity `SupplierContactEmail` (UUID) : contact, sentBy, subject, body, sentAt, status
- Page `/app/fournisseurs/contacts` : liste groupée par fournisseur
- Formulaire envoi email via `symfony/mailer`
- Rate limiting : 20 emails/h par user
- Tile "Contacts" dans hub fournisseurs avec badge count

**MERC-95** — Fiche fournisseur : produits + Commander
- Retirer bloc "Produits" de la fiche (déjà dans hub Produits)
- Champ `websiteUrl` sur Fournisseur + migration
- Bouton "Commander" contextuel (vert si URL, disabled sinon)
- `target="_blank" rel="noopener noreferrer"`

**Note** : MERC-95 est indépendant, peut être fait en parallèle de 92→93→94.

---

## Fait aujourd'hui (05/03) — Module Factures Fournisseurs

| Issue | Titre | Statut |
|-------|-------|--------|
| MERC-85 | State machine + CONTESTEE + audit trail + migration | ✅ Done |
| MERC-86 | Dashboard factures hub (6 tiles) | ✅ Done |
| MERC-87 | Factures reçues — split layout | ✅ Done |
| MERC-88 | Factures à payer — split layout + alertes échéance | ✅ Done |
| MERC-89 | Factures archive — split layout read-only | ✅ Done |
| MERC-90 | Taxes TVA déductible par période | ✅ Done |
| MERC-91 | Numérotation séquentielle factures | ✅ Done |

---

## Fait le 04/03 — E-invoicing B2Brouter

| Issue | Titre | Statut |
|-------|-------|--------|
| MERC-21 | DTOs + Interface PdpClientInterface | ✅ Done |
| MERC-19 | B2BRouterPdpClient (HTTP client) | ✅ Done |
| MERC-18 | Champs e-invoicing sur Etablissement | ✅ Done |
| MERC-16 | EInvoicingOnboardingService | ✅ Done |
| MERC-14 | Worker Messenger + entités FactureFournisseur | ✅ Done |
| MERC-10 | FactureWorkflowService + Voter + Controller + UI | ✅ Done |
| MERC-12 | InvoiceMatchingService (rapprochement facture/BL) | ✅ Done |
| MERC-8  | Tests unitaires et d'intégration | ✅ Done |

---

## Refonte UX/UI Back-Office (terminé)

| Issue | Titre | Statut |
|-------|-------|--------|
| MERC-71 | Composants UI réutilisables | ✅ Done |
| MERC-72 | Layout global header/breadcrumb | ✅ Done |
| MERC-73 | Tableau de bord hub central | ✅ Done |
| MERC-74 | Section Bons de livraison | ✅ Done |
| MERC-75 | Section Produits | ✅ Done |
| MERC-76 | Section Fournisseurs | ✅ Done |
| MERC-77 (old) | Section Profil | ✅ Done |
| MERC-86 | BL Pending master-detail split | ✅ Done |

---

## Refactoring Controllers BL (terminé)

| Issue | Titre | Statut |
|-------|-------|--------|
| MERC-58 | Extraire BonLivraisonImageService | ✅ Done |
| MERC-59 | Fusionner BonLivraisonConsultationController | ✅ Done |
| MERC-60 | Fusionner les controllers API BL | ✅ Done |
| MERC-61 | Audit routes et nettoyage | ✅ Done |

---

## Bugs et dette technique

| Issue | Description | Statut |
|-------|------------|--------|
| MERC-24 | 403 à l'upload import mercuriale en preprod | 🐛 Open |
| — | OCR : fuzzy match trop agressif | 🐛 Open |
| — | Pre-existing test failures : ColumnMapperTest (4), PWAAssetsTest (1), MercurialeImportControllerTest (3) | 🐛 Open |
