# SECURITY_AUDIT.md — Mercuriale.io

**Date :** 2026-03-20
**Auditeur :** Claude Opus 4.6
**Scope :** Code source complet, config Docker/Nginx, scripts deploy, Messenger, Stripe, Auth
**Branche :** `develop`

---

## Resume executif

**Score global : 6.5/10**

| Niveau   | Nombre |
|----------|--------|
| CRITIQUE | 2      |
| ELEVE    | 6      |
| MOYEN    | 7      |
| FAIBLE   | 3      |
| INFO     | 2      |

Le projet presente une bonne posture securitaire sur les axes auth, upload, IDOR et API Platform.
Les faiblesses principales se concentrent sur : la gestion des secrets en deploiement,
l'absence d'auth Redis, le routage Messenger incomplet, et quelques headers/configs Nginx manquants.

---

## Findings critiques

### SEC-01 — Secrets transmis en clair via SSH dans deploy-prod.sh

**Fichier :** `deploy-prod.sh:122-128`
**Criticite :** CRITIQUE

**Description :** Le script deploy injecte `STRIPE_SECRET_KEY` dans `.env.local` sur le serveur
distant via `sed` dans une commande SSH. La valeur du secret est visible dans :
- la liste des processus (`ps aux`) sur le serveur distant pendant l'execution
- l'historique bash local (`~/.bash_history`)
- les logs SSH selon la config serveur

**Preuve de concept :**
```bash
# Sur le serveur distant, pendant le deploy :
$ ps aux | grep sed
user  1234  sed -i s|^STRIPE_SECRET_KEY=.*|STRIPE_SECRET_KEY=sk_live_51Szg...|
```

**Recommandation :** Ne plus passer de secrets via CLI. Utiliser exclusivement GitHub Actions Secrets
via le workflow `.github/workflows/deploy.yml` (deja en place). Supprimer le mecanisme sed du script
manuel, ou utiliser stdin :
```bash
ssh "$SSH_USER@$SSH_HOST" "cat >> $REMOTE_DIR/.env.local" <<< "STRIPE_SECRET_KEY=$STRIPE_SECRET_KEY"
```

---

### SEC-02 — Redis sans authentification

**Fichier :** `docker-compose.yml:73-78`
**Criticite :** CRITIQUE

**Description :** Le service Redis ne configure aucun `requirepass`. Tout conteneur sur le reseau
Docker `mercuriale` peut lire/ecrire dans Redis sans authentification.

Redis est utilise comme transport Messenger (`redis://redis:6379/messages`). Un attaquant ayant
acces au reseau Docker peut :
- Lire les messages en queue (contenant des IDs de BL/factures)
- Injecter des messages malveillants dans la queue async
- Vider la queue (perte de jobs OCR)

**Recommandation :**
```yaml
# docker-compose.yml
redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}

# .env
REDIS_PASSWORD=un_mot_de_passe_fort_genere
MESSENGER_TRANSPORT_DSN=redis://:${REDIS_PASSWORD}@redis:6379/messages
```

---

## Findings eleves

### SEC-03 — ProcessBonLivraisonOcrMessage non route en async

**Fichier :** `config/packages/messenger.yaml:20-27`
**Criticite :** ELEVE

**Description :** Le message `ProcessBonLivraisonOcrMessage` (cree dans ce sprint) n'est pas
declare dans la section `routing:` de messenger.yaml. Sans routage explicite, Symfony Messenger
le traite de facon **synchrone** — l'OCR bloque la requete HTTP du controller `extraire()`.

Avec un timeout Anthropic API de 60 secondes, la requete web risque un timeout 504 Nginx.

**Recommandation :**
```yaml
routing:
    'App\Message\ProcessBonLivraisonOcrMessage': async  # AJOUTER
    'App\Message\ProcessFactureOcrMessage': async
    'App\Message\FetchPendingInvoicesMessage': async
```

---

### SEC-04 — Dockerfile : display_errors=On et opcache desactive

**Fichier :** `Dockerfile:39-44`
**Criticite :** ELEVE

**Description :** Le Dockerfile principal (utilise en preprod/prod) contient :
```dockerfile
RUN echo "display_errors=On" >> /usr/local/etc/php/conf.d/preprod.ini
RUN echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/opcache.ini
```

`display_errors=On` en production expose des traces d'erreur contenant chemins internes,
variables d'environnement, et stack traces aux utilisateurs.

**Recommandation :** Separer les configs PHP par environnement :
```dockerfile
# Dockerfile
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
# Contenu : display_errors=Off, opcache.enable=1, opcache.validate_timestamps=0
```

---

### SEC-05 — Images Docker non epinglees (tags flottants)

**Fichier :** `Dockerfile:1`, `docker-compose.yml:45,61,74,81`
**Criticite :** ELEVE

**Description :** Toutes les images de base utilisent des tags flottants :
- `php:8.4-fpm` (pas de version patch)
- `nginx:alpine`
- `postgres:16-alpine`
- `redis:7-alpine`
- `axllent/mailpit` (pas de tag du tout)

Un `docker build` a des moments differents peut produire des images differentes,
introduisant des vulnerabilites non detectees ou des regressions.

**Recommandation :** Epingler a des versions specifiques :
```dockerfile
FROM php:8.4.5-fpm-bookworm
```
```yaml
nginx: nginx:1.27.3-alpine3.21
postgres: postgres:16.6-alpine3.21
redis: redis:7.4.2-alpine3.21
```

---

### SEC-06 — Pas de docker-compose.prod.yml (volume mount en prod)

**Fichier :** `docker-compose.yml:10,31,52`
**Criticite :** ELEVE

**Description :** Le docker-compose.yml monte le code source en volume (`.:/var/www/html`).
C'est correct en dev, mais aucun `docker-compose.prod.yml` n'existe pour la production
avec `COPY` dans l'image. Si le compose dev est utilise en prod :
- Le code source est mutable a chaud
- Les fichiers `.env.local`, `config/jwt/` sont accessibles depuis le host

**Recommandation :** Creer un `docker-compose.prod.yml` qui utilise un Dockerfile multi-stage
avec `COPY . /var/www/html` et sans volumes de code.

---

### SEC-07 — Worker Messenger time-limit trop long (3600s)

**Fichier :** `docker-compose.yml:29`
**Criticite :** ELEVE

**Description :** Le worker Messenger est configure avec `--time-limit=3600` (1 heure).
En cas de fuite memoire ou de connexion BDD stale, le worker ne recupere qu'apres 1 heure.
Pas de `--memory-limit` configure.

**Recommandation :**
```yaml
command: php bin/console messenger:consume async --time-limit=600 --memory-limit=256M -vv
```

---

### SEC-08 — Remember-me cookie : flags de securite implicites

**Fichier :** `config/packages/security.yaml:44-46`
**Criticite :** ELEVE

**Description :** La config remember_me ne declare pas explicitement `secure`, `httponly`,
ni `samesite`. Symfony applique des defaults raisonnables, mais le cookie refresh JWT
(gesdinet) est configure avec `secure: true`, `http_only: true`, `same_site: strict`.
Le cookie remember_me devrait suivre le meme niveau de securite.

**Recommandation :**
```yaml
remember_me:
    secret: '%kernel.secret%'
    lifetime: 604800
    secure: true
    httponly: true
    samesite: strict
```

---

## Findings moyens

### SEC-09 — Nginx : composer.json/lock accessibles

**Fichiers :** `docker/nginx/default.conf:61-67`, `docker/nginx/nginx-prod.conf:75-78`
**Criticite :** MOYEN

**Description :** Les configs Nginx bloquent `.env`, `.git`, `.htaccess` mais pas
`composer.json`, `composer.lock`, le repertoire `var/`, `config/`, ni les fichiers `*.log`.
Un attaquant peut enumerer toutes les dependances PHP et leurs versions pour cibler des CVE.

**Recommandation :**
```nginx
location ~* (composer\.(json|lock)|package\.json|webpack\.config\.js)$ {
    deny all;
}
location ~ ^/(var|config)/ {
    deny all;
}
```

---

### SEC-10 — CSP avec unsafe-inline

**Fichier :** `docker/nginx/default.conf:47`, `docker/nginx/nginx-prod.conf:24`
**Criticite :** MOYEN

**Description :** La Content-Security-Policy autorise `'unsafe-inline'` dans `script-src`
et `style-src`, ainsi que `data:` dans `script-src`. Cela reduit significativement la
protection contre les XSS.

**Recommandation :** Migrer vers des nonces CSP ou des hashes pour les scripts inline.
Retirer `data:` de `script-src`.

---

### SEC-11 — server_tokens non configure explicitement

**Fichier :** `docker/nginx/default.conf`, `docker/nginx/nginx-prod.conf`
**Criticite :** MOYEN

**Description :** Aucun `server_tokens off;` n'est declare. Le header `Server: nginx/x.x.x`
expose la version de Nginx.

**Recommandation :** Ajouter `server_tokens off;` dans le bloc `server` de chaque config.

---

### SEC-12 — Retry Messenger insuffisant pour les APIs externes

**Fichier :** `config/packages/messenger.yaml:9-11`
**Criticite :** MOYEN

**Description :** La strategie de retry (max_retries: 3, multiplier: 2) produit des delais
de 1s, 2s, 4s. Avec un timeout Anthropic de 60s, si l'API est temporairement indisponible,
3 retries en ~7 secondes ne suffisent pas.

**Recommandation :**
```yaml
retry_strategy:
    max_retries: 5
    delay: 5000
    multiplier: 3
    max_delay: 60000
```

---

### SEC-13 — STRIPE_WEBHOOK_SECRET vide en prod

**Fichier :** `.env.deploy.local:5`
**Criticite :** MOYEN

**Description :** `STRIPE_WEBHOOK_SECRET=""` est vide dans la config de deploiement.
Le controller webhook (`StripeWebhookController.php:34-35`) rejette correctement les
requetes si le secret est vide, mais cela signifie que **tous les webhooks Stripe sont
refuses en prod** — les evenements de paiement ne sont pas traites.

**Recommandation :** Configurer le webhook secret dans le Dashboard Stripe et le renseigner
dans `.env.deploy.local` ou GitHub Secrets.

---

### SEC-14 — Webhook Stripe sans rate limiting

**Fichier :** `src/Controller/Webhook/StripeWebhookController.php`
**Criticite :** MOYEN

**Description :** Aucun rate limiting n'est applique sur la route `/webhook/stripe`.
Un attaquant peut envoyer un volume eleve de requetes (meme avec des signatures invalides)
pour consommer des ressources serveur.

**Recommandation :** Ajouter un rate limiter par IP dans `rate_limiter.yaml` :
```yaml
stripe_webhook:
    policy: sliding_window
    limit: 100
    interval: '1 minute'
```

---

### SEC-15 — Redis sans persistance (perte de messages)

**Fichier :** `docker-compose.yml:73-78`
**Criticite :** MOYEN

**Description :** Redis utilise la config par defaut (in-memory uniquement). Si le conteneur
Redis crash, tous les messages Messenger en queue sont perdus (jobs OCR, emails).

**Recommandation :**
```yaml
redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis_data:/data
```

---

## Findings faibles

### SEC-16 — Pas de USER explicite dans le Dockerfile principal

**Fichier :** `Dockerfile`
**Criticite :** FAIBLE

**Description :** Le Dockerfile modifie l'UID de `www-data` (ligne 48) mais ne declare pas
`USER www-data`. Le conteneur tourne implicitement en non-root via PHP-FPM, mais ce n'est
pas explicite.

**Recommandation :** Ajouter `USER www-data` apres la configuration.

---

### SEC-17 — Session fixation strategy implicite

**Fichier :** `config/packages/security.yaml`
**Criticite :** FAIBLE

**Description :** Aucune `session_fixation_strategy` n'est configuree. Symfony utilise
`migrate` par defaut (regeneration du session ID apres login), ce qui est correct.
Rendre explicite pour la documentation.

**Recommandation :**
```yaml
form_login:
    # ...
    session_fixation_strategy: migrate
```

---

### SEC-18 — Autoindex non explicitement desactive

**Fichier :** `docker/nginx/default.conf`, `docker/nginx/nginx-prod.conf`
**Criticite :** FAIBLE

**Description :** `autoindex off` n'est pas declare explicitement. Nginx desactive le
directory listing par defaut, mais une config upstream pourrait le reactiver.

**Recommandation :** Ajouter `autoindex off;` dans chaque bloc `server`.

---

## Findings informatifs

### SEC-19 — API Platform title par defaut

**Fichier :** `config/packages/api_platform.yaml:2`
**Criticite :** INFO

**Description :** Le titre est encore `Hello API Platform`. Visible sur `/api/docs`.
Pas de risque securitaire, mais indique une config non personnalisee.

**Recommandation :** Changer en `title: Mercuriale API`.

---

### SEC-20 — Config dev Nginx sans aucun header de securite

**Fichier :** `docker/nginx/default.dev.conf`
**Criticite :** INFO

**Description :** La config dev ne contient aucun header de securite (X-Frame-Options,
CSP, HSTS, etc.). Acceptable en dev local, mais si cette config est accidentellement
utilisee en prod, l'application perd toute protection header.

Le docker-compose.yml utilise `default.conf` (preprod, avec headers) et non
`default.dev.conf`, donc le risque est limite.

---

## Points conformes

| Axe | Description | Statut |
|-----|-------------|--------|
| 1.1 | Pas de secrets hardcodes dans le code source PHP/JS/YAML | RAS |
| 1.2 | `.env.local`, `.env.*.local`, `*.pem` dans `.gitignore` et non trackes | RAS |
| 1.4 | JWT private.pem en chmod 600 | RAS |
| 2.1 | Headers de securite complets en preprod et prod (7/7) | RAS |
| 2.2 | HTTPS force + HSTS max-age=31536000 en preprod et prod | RAS |
| 2.4 | PHP-FPM utilise `$realpath_root` (pas de path traversal) | RAS |
| 2.6 | client_max_body_size 25M (adapte aux uploads BL) | RAS |
| 3.1 | Ports DB et Redis non exposes sur l'interface publique | RAS |
| 3.7 | Aucun conteneur en mode privileged ou avec capabilities dangereuses | RAS |
| 3.8 | Mailpit en expose uniquement, pas en ports | RAS |
| 5.1 | Failed transport configure (`doctrine://default?queue_name=failed`) | RAS |
| 6.1 | Pas d'ApiResource sans securite (endpoints manuels avec IsGranted) | RAS |
| 6.2 | Pas de Mass Assignment (serialisation manuelle) | RAS |
| 6.3 | Pas de filtres API cross-tenant (requetes scoped par organisation) | RAS |
| 7.1 | Webhook Stripe valide la signature HMAC (constructEvent) | RAS |
| 7.4 | Pas de montants de paiement cote client (Stripe Identity uniquement) | RAS |
| 8.1 | Validation MIME + magic bytes + scan contenu suspect | RAS |
| 8.2 | Noms de fichiers UUID, jamais le nom original | RAS |
| 8.3 | Repertoire upload hors document_root, headers securises | RAS |
| 8.4 | Limites de taille coherentes (PHP 20M, Nginx 25M, Symfony 20M) | RAS |
| 8.6 | Suppression image apres OCR implementee avec protection path traversal | RAS |
| 9.2 | Refresh token rotation activee (single_use: true) | RAS |
| 9.3 | Logout en POST uniquement via firewall Symfony | RAS |
| 9.4 | Password hashing en auto (Argon2id) | RAS |

---

## Recommandations prioritaires

| Priorite | ID | Action | Impact | Effort |
|----------|----|--------|--------|--------|
| 1 | SEC-02 | Ajouter `requirepass` a Redis | Bloque l'injection de messages malveillants | Faible |
| 2 | SEC-03 | Router `ProcessBonLivraisonOcrMessage` en async | L'OCR est actuellement synchrone (bug) | Trivial |
| 3 | SEC-01 | Migrer les secrets deploy vers GitHub Secrets uniquement | Elimine l'exposition CLI | Moyen |
| 4 | SEC-04 | Separer configs PHP dev/prod dans le Dockerfile | Empeche display_errors en prod | Faible |
| 5 | SEC-09 | Bloquer composer.json/lock et /var/ /config/ dans Nginx | Reduit la surface d'enumeration | Trivial |

---

## Fichiers manquants

| Fichier attendu | Statut |
|-----------------|--------|
| `docker-compose.prod.yml` | Inexistant — pas de config Docker specifique prod |
| `config/secrets/` | Inexistant — Symfony Secrets Vault non utilise |
| `supervisord.conf` | Inexistant — worker supervise par Docker restart uniquement |

---

*Audit realise sur le commit `99a4758` (branche develop). Ne constitue pas un pentest.*
*Les fichiers `.env.local` et `.env.deploy.local` ne sont PAS trackes par git (verifie).*
