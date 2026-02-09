# Règles du projet Mercuriale.io

## CSS — RÈGLE STRICTE
- Tous les styles CSS vont dans des fichiers `.css` séparés dans le dossier approprié
- INTERDIT : balises `<style>` dans le HTML
- INTERDIT : attributs `style=""` inline sur les éléments HTML
- Les fichiers CSS sont référencés via `<link rel="stylesheet">`
- Cette règle s'applique à TOUS les fichiers : templates Twig, pages HTML statiques, etc.

## Sécurité
- Aucun token/secret dans le code source
- CSP strict active
- sw.js servi sans cache