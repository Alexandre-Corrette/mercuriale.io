# Audit UX — Sidebar Admin Mercuriale.io

> Date : 2026-02-19 | Ticket : MERC-32 follow-up

---

## 1. Structure actuelle (vue ADMIN)

```
OPÉRATIONS
├── Uploader un BL              ← action mise en avant
├── Bons de livraison           ← liste CRUD
├── Lignes BL                   ← liste CRUD (donnée de détail)
├── Import mercuriale           ← action mise en avant
└── Alertes                     ← liste CRUD

RÉFÉRENTIELS
├── Fournisseurs & Produits ▸   ← sous-menu, 4 items
│   ├── Fournisseurs
│   ├── Associations Fournisseurs  (SUPER_ADMIN uniquement)
│   ├── Produits
│   └── Mercuriale (prix)
├── Catalogue ▸                 ← sous-menu, 2 items
│   ├── Catalogue interne          (SUPER_ADMIN uniquement)
│   └── Catégories
└── Unités ▸                    ← sous-menu, 2 items
    ├── Unités
    └── Conversions

ADMINISTRATION
└── Configuration ▸            ← sous-menu, 4 items
    ├── Établissements
    ├── Utilisateurs
    ├── Droits établissements
    └── Organisations
```

**Dashboard** duplique : 4 cartes stats (BL, Alertes, Produits, Etablissements) + section "Acces rapide" avec 7 liens vers les memes pages.

**Total visible pour un ADMIN** : 14 items + 4 sous-menus a deployer.

---

## 2. Problemes identifies

| # | Probleme | Impact utilisateur |
|---|----------|--------------------|
| 1 | **"Lignes BL" en item principal** | L'utilisateur raisonne en BL, pas en lignes. C'est un detail accessible depuis la fiche BL. |
| 2 | **"Mercuriale (prix)" vs "Import mercuriale"** | Meme mot "mercuriale" dans 2 sections differentes. L'utilisateur ne sait pas ou aller. |
| 3 | **"Associations Fournisseurs" visible** | Terme technique (table de jonction). Aucun sens metier pour l'utilisateur. |
| 4 | **3 sous-menus avec 2 items chacun** | Clic supplementaire pour acceder a des pages peu profondes. "Catalogue" n'affiche que "Categories" pour la plupart des users. |
| 5 | **"Droits etablissements"** | Concept technique. L'utilisateur pense "qui a acces a quoi", pas "UtilisateurEtablissement". |
| 6 | **"Acces rapide" du dashboard** | 7 liens qui dupliquent exactement la sidebar. |
| 7 | **Cartes stats du dashboard** | Affichent toutes "-" (placeholder). Aucune valeur ajoutee. |
| 8 | **Surcharge cognitive** | 14 items visibles pour une app qui a 3 usages : upload, verification, gestion des prix. |

---

## 3. Proposition de restructuration

### Nouvelle sidebar

```
─── QUOTIDIEN ──────────────────────────────────

  [camera]   Scanner un BL                  (CTA principal, mis en avant)
  [list]     Bons de livraison              (liste BL, lignes dans la fiche detail)
  [alert]    Alertes

─── MERCURIALE ─────────────────────────────────

  [import]   Importer des prix              (flux d'import CSV/Excel)
  [tags]     Prix negocies                  (CRUD Mercuriale = prix par produit)
  [truck]    Fournisseurs                   (CRUD Fournisseur)
  [box]      Produits fournisseur           (CRUD ProduitFournisseur)

─── PARAMETRES ──────────────── (ADMIN+) ──────

  [store]    Etablissements
  [users]    Utilisateurs & acces           (fusion Utilisateurs + Droits)
  [ruler]    Unites & conversions           (fusion en une seule vue)
  [folder]   Categories
```

### Changements cles

| Action | Justification |
|--------|---------------|
| **Supprimer "Lignes BL"** du menu | Accessible depuis la fiche detail d'un BL |
| **Supprimer "Associations Fournisseurs"** | Outil interne SUPER_ADMIN, pas un concept utilisateur |
| **Supprimer "Catalogue interne"** | SUPER_ADMIN uniquement, accessible par URL |
| **Supprimer "Organisations"** | Rarement modifie, accessible par URL |
| **Aplatir tous les sous-menus** | Navigation directe, zero clic supplementaire |
| **Renommer** les items | Vocabulaire utilisateur : "Scanner", "Importer des prix", "Prix negocies" |
| **Fusionner "Utilisateurs" + "Droits"** | Un seul concept : gerer qui a acces a quoi |
| **Fusionner "Unites" + "Conversions"** | Un seul concept : gerer les unites de mesure |

### Resultat

| Avant | Apres |
|-------|-------|
| 14 items visibles | 10 items visibles |
| 4 sous-menus a deployer | 0 sous-menu |
| 3 sections | 3 sections |
| Termes techniques | Vocabulaire metier |

---

## 4. Dashboard

### Supprimer
- Section "Acces rapide" (redondante avec la sidebar)

### Ameliorer
- **Cartes stats** : remplacer les "-" par des vrais compteurs (requetes Doctrine)
  - BL du mois en cours
  - Alertes non traitees
  - Produits actifs
  - Etablissements actifs

### Ajouter (optionnel)
- Derniers BL uploades (5 derniers)
- Alertes recentes non traitees (5 dernieres)

---

## 5. Items supprimes du menu (toujours accessibles)

Ces pages restent fonctionnelles via URL directe, elles ne sont simplement plus dans la navigation :

| Page | URL | Qui en a besoin |
|------|-----|-----------------|
| Associations Fournisseurs | `/admin?crudController=OrganisationFournisseurCrudController` | SUPER_ADMIN |
| Catalogue interne | `/admin?crudController=ProduitCrudController` | SUPER_ADMIN |
| Organisations | `/admin?crudController=OrganisationCrudController` | SUPER_ADMIN |
| Lignes BL | `/admin?crudController=LigneBonLivraisonCrudController` | Debug |
