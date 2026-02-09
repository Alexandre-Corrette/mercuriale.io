#!/bin/bash
# =============================================================================
# 🚀 Mercuriale.io — Script de déploiement PREPROD (OVH mutualisé)
# =============================================================================
# Usage : ./deploy-preprod.sh [--skip-build] [--skip-migrations] [--dry-run]
# =============================================================================

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
SSH_HOST="ssh.cluster131.hosting.ovh.net"
SSH_USER="taajqxv"  # ⚠️ À remplir avec ton user OVH
REMOTE_DIR="~/test"
REMOTE_PUBLIC="~/test/public"
GIT_BRANCH="develop"  # ⚠️ Adapte si ta branche preprod a un autre nom
PHP_REMOTE="php"       # Sur OVH mutualisé, parfois "php8.2" ou chemin complet

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ── Flags ────────────────────────────────────────────────────────────────────
SKIP_BUILD=false
SKIP_MIGRATIONS=false
DRY_RUN=false

for arg in "$@"; do
    case $arg in
        --skip-build) SKIP_BUILD=true ;;
        --skip-migrations) SKIP_MIGRATIONS=true ;;
        --dry-run) DRY_RUN=true ;;
        *) echo -e "${RED}❌ Argument inconnu : $arg${NC}"; exit 1 ;;
    esac
done

# ── Fonctions utilitaires ────────────────────────────────────────────────────
log_step() { echo -e "\n${BLUE}━━━ $1 ━━━${NC}"; }
log_ok()   { echo -e "${GREEN}✅ $1${NC}"; }
log_warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
log_err()  { echo -e "${RED}❌ $1${NC}"; }

ssh_exec() {
    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}[DRY-RUN] ssh $SSH_USER@$SSH_HOST \"$1\"${NC}"
    else
        ssh "$SSH_USER@$SSH_HOST" "$1"
    fi
}

# ── Vérifications préalables ─────────────────────────────────────────────────
log_step "1/7 — Vérifications préalables"

if [ -z "$SSH_USER" ]; then
    log_err "SSH_USER non configuré. Édite le script et renseigne ton user OVH."
    exit 1
fi

# Vérifier qu'on est dans le bon repo
if [ ! -f "composer.json" ]; then
    log_err "Ce script doit être lancé depuis la racine du projet Mercuriale.io"
    exit 1
fi

# Vérifier la branche courante
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "$GIT_BRANCH" ]; then
    log_warn "Tu es sur la branche '$CURRENT_BRANCH', pas sur '$GIT_BRANCH'"
    read -p "Continuer quand même ? (y/N) " -n 1 -r
    echo
    [[ ! $REPLY =~ ^[Yy]$ ]] && exit 0
fi

# Vérifier les changements non commités
if ! git diff-index --quiet HEAD -- 2>/dev/null; then
    log_warn "Il y a des changements non commités !"
    git status --short
    read -p "Continuer quand même ? (y/N) " -n 1 -r
    echo
    [[ ! $REPLY =~ ^[Yy]$ ]] && exit 0
fi

log_ok "Vérifications OK — branche: $CURRENT_BRANCH"

# ── Build local des assets ───────────────────────────────────────────────────
if [ "$SKIP_BUILD" = false ]; then
    log_step "2/7 — Build local des assets"

    if [ -f "package.json" ]; then
        npm install --silent
        npm run build
        log_ok "Assets compilés"
    else
        log_warn "Pas de package.json trouvé, skip du build assets"
    fi

    # Vérifier que les assets buildés sont commités
    if ! git diff-index --quiet HEAD -- public/build 2>/dev/null; then
        log_warn "Les assets buildés ont changé, commit en cours..."
        git add public/build/
        git commit -m "chore: build assets pour déploiement preprod"
    fi
else
    log_step "2/7 — Build local des assets (SKIPPED)"
fi

# ── Push vers GitHub ─────────────────────────────────────────────────────────
log_step "3/7 — Push vers GitHub"

if [ "$DRY_RUN" = false ]; then
    git push origin "$CURRENT_BRANCH"
    log_ok "Push OK sur origin/$CURRENT_BRANCH"
else
    echo -e "${YELLOW}[DRY-RUN] git push origin $CURRENT_BRANCH${NC}"
fi

# ── Déploiement distant ─────────────────────────────────────────────────────
log_step "4/7 — Git pull sur le serveur"

ssh_exec "cd $REMOTE_DIR && git fetch origin && git reset --hard origin/$CURRENT_BRANCH"
log_ok "Code mis à jour sur le serveur"

# ── Composer install ─────────────────────────────────────────────────────────
log_step "5/7 — Composer install (production)"

ssh_exec "cd $REMOTE_DIR && $PHP_REMOTE composer.phar install --no-dev --optimize-autoloader --no-interaction 2>&1"
log_ok "Dépendances installées"

# ── Migrations Doctrine ──────────────────────────────────────────────────────
if [ "$SKIP_MIGRATIONS" = false ]; then
    log_step "6/7 — Migrations Doctrine"

    # Vérifier s'il y a des migrations en attente
    PENDING=$(ssh_exec "cd $REMOTE_DIR && $PHP_REMOTE bin/console doctrine:migrations:status --no-interaction 2>&1 | grep -c 'not migrated' || true")

    if [ "$PENDING" != "0" ] && [ -n "$PENDING" ]; then
        log_warn "Migrations en attente détectées"
        if [ "$DRY_RUN" = false ]; then
            read -p "Lancer les migrations ? (y/N) " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                ssh_exec "cd $REMOTE_DIR && $PHP_REMOTE bin/console doctrine:migrations:migrate --no-interaction 2>&1"
                log_ok "Migrations exécutées"
            else
                log_warn "Migrations ignorées"
            fi
        fi
    else
        log_ok "Aucune migration en attente"
    fi
else
    log_step "6/7 — Migrations Doctrine (SKIPPED)"
fi

# ── Cache & finalisation ────────────────────────────────────────────────────
log_step "7/7 — Cache clear & finalisation"

ssh_exec "cd $REMOTE_DIR && $PHP_REMOTE bin/console cache:clear --env=prod --no-interaction 2>&1"
ssh_exec "cd $REMOTE_DIR && $PHP_REMOTE bin/console cache:warmup --env=prod --no-interaction 2>&1"

# Permissions JWT
ssh_exec "chmod 600 $REMOTE_DIR/config/jwt/private.pem 2>/dev/null || true"

# Vérification .htaccess HTTPS
ssh_exec "grep -q 'RewriteCond.*HTTPS.*off' $REMOTE_PUBLIC/.htaccess 2>/dev/null && echo 'HTTPS forcé OK' || echo 'WARN: HTTPS non forcé dans .htaccess'"

log_ok "Cache vidé et réchauffé"

# ── Résumé ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  🚀 Déploiement PREPROD terminé !${NC}"
echo -e "${GREEN}  🌐 https://test.mercuriale.io${NC}"
echo -e "${GREEN}  📅 $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo -e "${GREEN}  🔀 Branche : $CURRENT_BRANCH${NC}"
echo -e "${GREEN}  📝 Commit  : $(git log --oneline -1)${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
