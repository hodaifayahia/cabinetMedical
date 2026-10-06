#!/usr/bin/env bash
# Puts the latest version of Drclick on the online server (drclickdz.com).
#
# Over SSH, from anywhere:
#
#   bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh            # deploy main
#   bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh my-branch  # another branch
#   bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh --check    # only check, change nothing
#   bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh --rollback # back to the previous version
#
# What a deploy does, in order:
#   1. checks PHP 8.3+, Composer, git and .env; refuses to run twice at once;
#   2. encrypted database backup (scripts/server/nightly-backup.sh);
#   3. maintenance mode, then the new code (git), PHP packages (composer),
#      the built frontend (public/build), database migrations and caches;
#   4. back online, then a health check on /up.
# If anything fails after step 3 started, the previous code, packages and
# frontend are put back automatically and the site comes back online.
# Database migrations are not undone (they only add tables and columns).
#
# Frontend: the files built by GitHub Actions (.github/workflows/web-build.yml)
# are taken from the branch web-build-<branch>, with the git access the server
# already has, before the site goes into maintenance. Shared hosting usually
# has no Node.js; DRCLICK_ASSETS=node builds here instead (slower, and done
# during maintenance since it needs the new code).
#
# Settings (environment variables, all optional):
#   DRCLICK_APP_DIR      app folder (default: this script's checkout)
#   DRCLICK_PHP          PHP 8.3+ binary (default: first found)
#   DRCLICK_COMPOSER     composer command (default: composer, or a local composer.phar)
#   DRCLICK_SKIP_BACKUP  1 = no database backup before deploying
#   DRCLICK_FORCE        1 = deploy even if already up to date or the server has local edits
#   DRCLICK_ASSETS       github (default) | node
#   DRCLICK_ASSET_WAIT   seconds to wait for GitHub to finish the frontend build (default 900)
set -Eeuo pipefail
umask 022

APP_DIR="${DRCLICK_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
# Run from a copy of the script: use the current folder, or the usual
# Hostinger location.
if [ -z "${DRCLICK_APP_DIR:-}" ] && [ ! -f "$APP_DIR/artisan" ]; then
    for candidate in "$PWD" "$HOME/domains/drclickdz.com/backend-laravel"; do
        if [ -f "$candidate/artisan" ]; then
            APP_DIR="$candidate"
            break
        fi
    done
fi
STATE_DIR="${DRCLICK_DEPLOY_STATE_DIR:-$HOME/.local/state/drclick-deploy}"
LOG_FILE="$STATE_DIR/deploy.log"
ASSETS_MODE="${DRCLICK_ASSETS:-github}"
ASSET_WAIT="${DRCLICK_ASSET_WAIT:-900}"
MODE="deploy"
BRANCH="main"

case "${1:-}" in
    -h|--help) sed -n '2,36p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    --check) MODE="check" ;;
    --rollback) MODE="rollback" ;;
    '') ;;
    -*) echo "Option inconnue : $1 (voir --help)" >&2; exit 2 ;;
    *) BRANCH="$1" ;;
esac

mkdir -p "$STATE_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok() { printf '\033[0;32m    ✔ %s\033[0m\n' "$*"; }
warn() { printf '\033[0;33m    ! %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

printf '\n----- %s : deploy.sh %s (%s) -----\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$MODE" "$BRANCH"

# --- Tools -------------------------------------------------------------------

php_ok() { "$1" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1; }

find_php() {
    local candidate
    for candidate in "${DRCLICK_PHP:-}" php php8.4 php8.3 /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /usr/bin/php8.4 /usr/bin/php8.3; do
        [ -n "$candidate" ] || continue
        if command -v "$candidate" >/dev/null 2>&1 && php_ok "$candidate"; then
            command -v "$candidate"
            return 0
        fi
    done
    return 1
}

PHP_BIN="$(find_php)" || die "PHP 8.3 ou plus récent est introuvable. Indiquez-le : DRCLICK_PHP=/chemin/vers/php bash $0"

COMPOSER=()
find_composer() {
    local path
    if [ -n "${DRCLICK_COMPOSER:-}" ]; then
        read -r -a COMPOSER <<< "$DRCLICK_COMPOSER"
        return 0
    fi
    if path="$(command -v composer 2>/dev/null)"; then
        # Run a composer phar with the PHP chosen above, not the default one;
        # a shell wrapper is run as it is.
        if head -n 1 "$path" | grep -qE '^#!.*[/ ]php[0-9.]*$'; then
            COMPOSER=("$PHP_BIN" "$path")
        else
            COMPOSER=("$path")
        fi
        return 0
    fi
    if [ ! -f "$STATE_DIR/composer.phar" ]; then
        warn "Composer introuvable : téléchargement de composer.phar"
        curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "$STATE_DIR/composer.phar" \
            || return 1
    fi
    COMPOSER=("$PHP_BIN" "$STATE_DIR/composer.phar")
}

artisan() { "$PHP_BIN" "$APP_DIR/artisan" "$@"; }

env_value() {
    # Reads KEY=value from .env (quotes removed), empty when absent.
    sed -n "s/^$1=//p" "$APP_DIR/.env" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

# --- Checks ------------------------------------------------------------------

preflight() {
    say "Vérifications"
    [ -f "$APP_DIR/artisan" ] || die "Pas d'application Laravel dans $APP_DIR (DRCLICK_APP_DIR ?)"
    cd "$APP_DIR"
    ok "Application : $APP_DIR"
    ok "PHP : $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

    find_composer || die "Composer est introuvable et n'a pas pu être téléchargé."
    ok "Composer : ${COMPOSER[*]}"

    command -v git >/dev/null 2>&1 || die "git est introuvable sur ce serveur."
    git -C "$APP_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || die "$APP_DIR n'est pas un dépôt git. Voir docs/DEPLOIEMENT.md, « Première installation »."
    ok "Version actuelle : $(git -C "$APP_DIR" log -1 --format='%h %s' 2>/dev/null)"

    [ -f "$APP_DIR/.env" ] || die "Fichier .env absent dans $APP_DIR."
    ok ".env présent"

    if [ -n "$(git -C "$APP_DIR" status --porcelain --untracked-files=no)" ]; then
        if [ "${DRCLICK_FORCE:-0}" = "1" ]; then
            warn "Fichiers modifiés sur le serveur : ils seront remplacés (DRCLICK_FORCE=1)."
            git -C "$APP_DIR" status --short --untracked-files=no | sed 's/^/      /'
        else
            git -C "$APP_DIR" status --short --untracked-files=no | sed 's/^/      /'
            die "Des fichiers du code ont été modifiés directement sur le serveur (liste ci-dessus).
Ils seraient écrasés. Sauvegardez-les si besoin, puis relancez avec DRCLICK_FORCE=1."
        fi
    fi

    local free_kb
    free_kb="$(df -Pk "$APP_DIR" | awk 'NR==2 {print $4}')"
    if [ -n "$free_kb" ] && [ "$free_kb" -lt 524288 ]; then
        warn "Moins de 512 Mo libres sur le disque ($((free_kb / 1024)) Mo)."
    fi
}

check_activation_key() {
    local key_path
    key_path="$(env_value MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH)"
    if [ -z "$key_path" ] || [ ! -r "$key_path" ]; then
        warn "Clé d'activation des postes non configurée : les nouveaux postes ne peuvent pas"
        warn "s'activer en ligne. Lancez une fois : bash $APP_DIR/scripts/server/setup-activation-key.sh"
    else
        ok "Clé d'activation des postes : $key_path"
    fi
}

# --- Frontend (public/build) -------------------------------------------------

node_usable() {
    command -v npm >/dev/null 2>&1 && command -v node >/dev/null 2>&1 \
        && node -e 'process.exit(parseInt(process.versions.node) >= 20 ? 0 : 1)' >/dev/null 2>&1
}

asset_branch() { printf 'web-build-%s' "$(printf '%s' "$BRANCH" | tr -c 'A-Za-z0-9._-' '-')"; }

# Fetches the GitHub build of $1 (a commit) into $2/build.
fetch_built_assets() {
    local commit="$1" destination="$2" branch built waited=0
    branch="$(asset_branch)"
    while :; do
        if git -C "$APP_DIR" fetch -q origin "+refs/heads/$branch:refs/remotes/origin/$branch" 2>/dev/null; then
            built="$(git -C "$APP_DIR" show "origin/$branch:SOURCE_COMMIT" 2>/dev/null | tr -d '[:space:]')"
            if [ "$built" = "$commit" ]; then
                mkdir -p "$destination"
                git -C "$APP_DIR" archive "origin/$branch" build | tar -x -C "$destination"
                [ -f "$destination/build/manifest.json" ] || die "La version compilée téléchargée n'a pas de manifest.json."
                ok "Interface compilée par GitHub ($branch)"
                return 0
            fi
        fi
        if [ "$waited" -ge "$ASSET_WAIT" ]; then
            die "GitHub n'a pas (encore) compilé l'interface de ${commit:0:7} sur la branche $branch.
Vérifiez l'onglet Actions › web-build du dépôt (il se lance à chaque envoi sur main,
ou à la main avec « Run workflow » pour une autre branche), puis relancez ce script."
        fi
        [ "$waited" -eq 0 ] && warn "En attente de la compilation de l'interface par GitHub (jusqu'à $((ASSET_WAIT / 60)) min)…"
        sleep 30
        waited=$((waited + 30))
    done
}

ASSETS_STAGED=""
prepare_assets() {
    # Before maintenance: download the GitHub build if that is the plan.
    local commit="$1"
    case "$ASSETS_MODE" in
        node)
            node_usable || die "DRCLICK_ASSETS=node demande Node.js 20 ou plus récent sur le serveur."
            return 0
            ;;
        github | branch | auto) ;;
        *) die "DRCLICK_ASSETS doit valoir github ou node." ;;
    esac
    ASSETS_STAGED="$STATE_DIR/assets-$commit"
    rm -rf "$ASSETS_STAGED"
    fetch_built_assets "$commit" "$ASSETS_STAGED"
}

install_assets() {
    if [ -n "$ASSETS_STAGED" ]; then
        rm -rf "$APP_DIR/public/build.new"
        mv "$ASSETS_STAGED/build" "$APP_DIR/public/build.new"
        rm -rf "$ASSETS_STAGED"
    else
        say "Compilation de l'interface sur le serveur (npm)"
        # Time limits: a stuck download must not keep the site in maintenance.
        (cd "$APP_DIR" && timeout 900 npm ci --no-audit --no-fund && timeout 600 npm run build)
        [ -f "$APP_DIR/public/build/manifest.json" ] || die "La compilation n'a pas produit public/build/manifest.json."
        ok "Interface compilée"
        return 0
    fi
    rm -rf "$APP_DIR/public/build.old"
    [ -d "$APP_DIR/public/build" ] && mv "$APP_DIR/public/build" "$APP_DIR/public/build.old"
    mv "$APP_DIR/public/build.new" "$APP_DIR/public/build"
    rm -rf "$APP_DIR/public/build.old"
    ok "Interface installée"
}

# --- Steps -------------------------------------------------------------------

backup_database() {
    if [ "${DRCLICK_SKIP_BACKUP:-0}" = "1" ]; then
        warn "Sauvegarde de la base ignorée (DRCLICK_SKIP_BACKUP=1)"
        return 0
    fi
    say "Sauvegarde chiffrée de la base avant la mise à jour"
    if [ -f "$APP_DIR/scripts/server/nightly-backup.sh" ]; then
        DRCLICK_APP_DIR="$APP_DIR" DRCLICK_PHP="$PHP_BIN" bash "$APP_DIR/scripts/server/nightly-backup.sh" \
            || die "La sauvegarde a échoué : rien n'a été modifié. Corrigez-la, ou relancez avec DRCLICK_SKIP_BACKUP=1."
        ok "Sauvegarde faite (~/drclick-backups)"
    else
        warn "scripts/server/nightly-backup.sh absent : pas de sauvegarde."
    fi
}

composer_install() {
    say "Paquets PHP (composer)"
    (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER[@]}" install \
        --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress) || return 1
    ok "Paquets PHP installés"
}

refresh_caches() {
    artisan optimize:clear >/dev/null || return 1
    artisan optimize || return 1
    ok "Caches reconstruits"
}

IN_MAINTENANCE=0
maintenance_on() {
    local secret
    secret="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    artisan down --retry=60 --refresh=30 --secret="$secret" >/dev/null
    IN_MAINTENANCE=1
    ok "Site en maintenance. Pour le voir pendant ce temps : $(env_value APP_URL)/$secret"
}

maintenance_off() {
    artisan up >/dev/null
    IN_MAINTENANCE=0
    ok "Site de nouveau en ligne"
}

health_check() {
    local url code
    url="$(env_value APP_URL)"
    [ -n "$url" ] || { warn "APP_URL absent du .env : vérification /up ignorée."; return 0; }
    for _ in 1 2 3 4 5; do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${url%/}/up" || true)"
        if [ "$code" = "200" ]; then
            ok "${url%/}/up répond 200"
            return 0
        fi
        sleep 4
    done
    printf '    réponse : %s\n' "${code:-aucune}"
    return 1
}

PREVIOUS=""
restore_previous() {
    # Puts back the code, packages, frontend and caches of $PREVIOUS. Every
    # step is checked explicitly: errexit does not apply inside an `if`.
    [ -n "$PREVIOUS" ] || return 1
    say "Retour à la version précédente (${PREVIOUS:0:7})"
    git -C "$APP_DIR" reset -q --hard "$PREVIOUS" || return 1
    composer_install || return 1
    if [ -d "$STATE_DIR/build.previous" ]; then
        rm -rf "$APP_DIR/public/build" || return 1
        cp -R "$STATE_DIR/build.previous" "$APP_DIR/public/build" || return 1
        ok "Interface précédente remise"
    fi
    refresh_caches || return 1
    artisan queue:restart >/dev/null 2>&1 || true
}

on_exit() {
    local status=$?
    trap - EXIT
    set +e
    [ "$status" -eq 0 ] && exit 0
    if [ "$MODE" = "deploy" ] && [ "$STARTED" = "1" ]; then
        printf '\n\033[1;31mLa mise à jour a échoué (code %s). Retour automatique à la version précédente…\033[0m\n' "$status"
        if restore_previous; then
            maintenance_off
            printf '\033[1;33mLe site tourne de nouveau sur la version précédente (%s).\033[0m\n' "${PREVIOUS:0:7}"
            printf 'Les migrations déjà passées sont conservées. Détails : %s\n' "$LOG_FILE"
        else
            printf '\033[1;31mLe retour automatique a échoué : le site reste en maintenance.\033[0m\n'
            printf 'Corrigez puis lancez : bash %s --rollback   (journal : %s)\n' "$0" "$LOG_FILE"
        fi
    elif [ "$IN_MAINTENANCE" = "1" ]; then
        artisan up >/dev/null 2>&1
        printf 'Site remis en ligne (aucune modification du code).\n'
    fi
    exit "$status"
}

STARTED=0
trap on_exit EXIT

# --- Modes ---------------------------------------------------------------------

exec 9> "$STATE_DIR/deploy.lock"
if command -v flock >/dev/null 2>&1; then
    flock -n 9 || die "Un autre déploiement est déjà en cours."
fi

preflight

if [ "$MODE" = "check" ]; then
    say "Comparaison avec GitHub ($BRANCH)"
    git fetch -q origin "$BRANCH" || die "Impossible de joindre le dépôt GitHub (accès git du serveur ?)."
    current="$(git rev-parse HEAD)"
    target="$(git rev-parse "origin/$BRANCH")"
    if [ "$current" = "$target" ]; then
        ok "Déjà à jour (${current:0:7})"
    else
        ok "Nouvelle version disponible : ${target:0:7} ($(git log -1 --format=%s "$target"))"
        git log --oneline "$current..$target" | head -20 | sed 's/^/      /'
    fi
    if [ "$ASSETS_MODE" = "node" ]; then
        ok "Interface compilée sur ce serveur (DRCLICK_ASSETS=node)"
    elif git fetch -q origin "+refs/heads/$(asset_branch):refs/remotes/origin/$(asset_branch)" 2>/dev/null; then
        built="$(git show "origin/$(asset_branch):SOURCE_COMMIT" 2>/dev/null | tr -d '[:space:]')"
        if [ "$built" = "$target" ]; then
            ok "Interface déjà compilée par GitHub pour ${target:0:7}"
        else
            warn "GitHub n'a pas encore compilé l'interface de ${target:0:7} (dernière : ${built:0:7}) : attendez l'action web-build."
        fi
    else
        warn "Branche $(asset_branch) absente : lancez l'action GitHub web-build (onglet Actions)."
    fi
    check_activation_key
    exit 0
fi

if [ "$MODE" = "rollback" ]; then
    PREVIOUS="$(cat "$STATE_DIR/previous-commit" 2>/dev/null || true)"
    [ -n "$PREVIOUS" ] || die "Aucune version précédente enregistrée (aucun déploiement fait avec ce script)."
    say "Retour à la version ${PREVIOUS:0:7}"
    maintenance_on
    restore_previous
    maintenance_off
    health_check || warn "Le site ne répond pas correctement sur /up : vérifiez $LOG_FILE et storage/logs."
    exit 0
fi

say "Récupération de la version $BRANCH depuis GitHub"
git fetch -q origin "$BRANCH" || die "Impossible de joindre le dépôt GitHub (accès git du serveur ?)."
CURRENT="$(git rev-parse HEAD)"
TARGET="$(git rev-parse "origin/$BRANCH")"
if [ "$CURRENT" = "$TARGET" ] && [ "${DRCLICK_FORCE:-0}" != "1" ]; then
    ok "Déjà à jour (${CURRENT:0:7}). Rien à faire. (DRCLICK_FORCE=1 pour réinstaller)"
    check_activation_key
    exit 0
fi
ok "${CURRENT:0:7} → ${TARGET:0:7} : $(git log -1 --format=%s "$TARGET")"

prepare_assets "$TARGET"
backup_database

say "Mise à jour"
PREVIOUS="$CURRENT"
printf '%s\n' "$PREVIOUS" > "$STATE_DIR/previous-commit"
rm -rf "$STATE_DIR/build.previous"
[ -d "$APP_DIR/public/build" ] && cp -R "$APP_DIR/public/build" "$STATE_DIR/build.previous"

maintenance_on
STARTED=1

git checkout -q -B "$BRANCH" "$TARGET"
git reset -q --hard "$TARGET"
ok "Code : $(git log -1 --format='%h %s')"

composer_install
install_assets

say "Base de données"
artisan migrate --force
artisan storage:link >/dev/null 2>&1 || true

say "Caches et file d'attente"
refresh_caches
artisan queue:restart >/dev/null 2>&1 || true

maintenance_off
STARTED=0

say "Vérification"
if ! health_check; then
    STARTED=1
    die "Le site ne répond pas correctement après la mise à jour." # on_exit restores the previous version
fi
printf '%s\n' "$TARGET" > "$STATE_DIR/current-commit"
check_activation_key

printf '\n\033[1;32mMise à jour terminée : %s est en ligne.\033[0m\n' "$(git log -1 --format='%h %s')"
printf 'Journal : %s   —   Revenir en arrière : bash %s --rollback\n' "$LOG_FILE" "$0"
