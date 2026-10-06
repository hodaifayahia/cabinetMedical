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
#   DRCLICK_WEB_ROOT     folder the web server serves, when it is not
#                        <app>/public (default: ../public_html when its
#                        index.php loads this app)
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
# Hostinger serves the site from a separate public_html folder whose
# index.php loads ../backend-laravel: public files are copied there too.
WEB_ROOT="${DRCLICK_WEB_ROOT:-}"
if [ -z "$WEB_ROOT" ] && [ -f "$APP_DIR/../public_html/index.php" ] \
    && [ "$(cd "$APP_DIR/../public_html" && pwd -P)" != "$(cd "$APP_DIR/public" 2>/dev/null && pwd -P)" ] \
    && grep -q "$(basename "$APP_DIR")/bootstrap/app.php" "$APP_DIR/../public_html/index.php"; then
    WEB_ROOT="$(cd "$APP_DIR/../public_html" && pwd)"
fi
STATE_DIR="${DRCLICK_DEPLOY_STATE_DIR:-$HOME/.local/state/drclick-deploy}"
LOG_FILE="$STATE_DIR/deploy.log"
ASSETS_MODE="${DRCLICK_ASSETS:-github}"
ASSET_WAIT="${DRCLICK_ASSET_WAIT:-900}"
MODE="deploy"
BRANCH="main"

case "${1:-}" in
    -h|--help) sed -n '2,/^set -E/p' "${BASH_SOURCE[0]}" | grep '^#' | sed 's/^# \{0,1\}//'; exit 0 ;;
    --check) MODE="check" ;;
    --rollback) MODE="rollback" ;;
    '') ;;
    -*) echo "Option inconnue : $1 (voir --help)" >&2; exit 2 ;;
    *) BRANCH="$1" ;;
esac

mkdir -p "$STATE_DIR"
# Everything printed also goes to the log. Shared hosting has no /dev/fd, so
# instead of a process substitution the script runs itself through tee.
if [ -z "${DRCLICK_DEPLOY_LOGGED:-}" ]; then
    set +e
    DRCLICK_DEPLOY_LOGGED=1 DRCLICK_APP_DIR="$APP_DIR" bash "${BASH_SOURCE[0]}" "$@" 2>&1 | tee -a "$LOG_FILE"
    exit "${PIPESTATUS[0]}"
fi

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
        local first_line
        IFS= read -r first_line < "$path" || true
        if [[ "$first_line" =~ ^\#!.*[/\ ]php[0-9.]*$ ]]; then
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

    if [ -n "$WEB_ROOT" ]; then
        [ -d "$WEB_ROOT" ] || die "Dossier public introuvable : $WEB_ROOT"
        ok "Dossier public du site : $WEB_ROOT"
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

# --- Files edited on the server ------------------------------------------------

# Code files changed on the server outside git. Those identical to a version
# in GitHub's history were deployed by hand earlier and are safe to replace;
# the others exist only here. A patch of all of them is always saved first.
LOCAL_PATCH=""
review_local_changes() {
    local list f blob found commit local_only=0 known=0 patch
    list="$STATE_DIR/local-changes.list"
    git -C "$APP_DIR" diff --name-only HEAD > "$list"
    [ -s "$list" ] || return 0

    patch="$STATE_DIR/local-changes-$(date '+%Y%m%d-%H%M%S').patch"
    git -C "$APP_DIR" diff --binary HEAD > "$patch"
    LOCAL_PATCH="$patch"
    : > "$STATE_DIR/local-only.list"

    while IFS= read -r f; do
        found=0
        if [ -e "$APP_DIR/$f" ]; then
            blob="$(git -C "$APP_DIR" hash-object -- "$f")"
            # --full-history: a merge that kept the other side still counts.
            for commit in $(git -C "$APP_DIR" log --full-history --format=%H "origin/$BRANCH" -- "$f"); do
                if [ "$(git -C "$APP_DIR" rev-parse -q --verify "$commit:$f" 2>/dev/null)" = "$blob" ]; then
                    found=1
                    break
                fi
            done
        elif ! git -C "$APP_DIR" cat-file -e "origin/$BRANCH:$f" 2>/dev/null; then
            found=1 # deleted here and gone from GitHub too
        fi
        if [ "$found" = 1 ]; then
            known=$((known + 1))
        else
            local_only=$((local_only + 1))
            printf '%s\n' "$f" >> "$STATE_DIR/local-only.list"
        fi
    done < "$list"

    if [ "$known" -gt 0 ]; then
        ok "$known fichier(s) modifié(s) à la main sur le serveur : déjà présent(s) dans GitHub"
    fi
    if [ "$local_only" -gt 0 ]; then
        warn "$local_only fichier(s) modifié(s) sur le serveur n'existent PAS dans GitHub :"
        sed 's/^/        /' "$STATE_DIR/local-only.list"
        warn "Copie de toutes les modifications : $patch"
        if [ "$MODE" = "deploy" ] && [ "${DRCLICK_FORCE:-0}" != "1" ]; then
            die "Ces changements seraient remplacés par la version de GitHub.
Si c'est voulu (ils sont déjà dans le nouveau code, ou inutiles), relancez avec :
  DRCLICK_FORCE=1 bash $0"
        fi
    else
        ok "Copie de sécurité des modifications : $patch"
    fi
}

# --- Web root (public_html) ---------------------------------------------------

# Copies the app's public files into the web root: the built frontend
# replaces build/ as a whole; tracked files are copied over. index.php,
# .htaccess, storage and anything else that only lives there are kept.
sync_web_root() {
    local list f
    [ -n "$WEB_ROOT" ] || return 0
    [ -f "$APP_DIR/public/build/manifest.json" ] || die "public/build/manifest.json manquant : rien à publier."

    rm -rf "$WEB_ROOT/build.drclick-deploy-new"
    cp -R "$APP_DIR/public/build" "$WEB_ROOT/build.drclick-deploy-new"
    rm -rf "$WEB_ROOT/build.drclick-deploy-old"
    [ -d "$WEB_ROOT/build" ] && mv "$WEB_ROOT/build" "$WEB_ROOT/build.drclick-deploy-old"
    mv "$WEB_ROOT/build.drclick-deploy-new" "$WEB_ROOT/build"
    rm -rf "$WEB_ROOT/build.drclick-deploy-old"

    copy_public_files || die "Copie des fichiers publics vers $WEB_ROOT impossible."
    ok "Fichiers publics copiés dans $WEB_ROOT"
}

# Every file of <app>/public (including assets composer publishes, which are
# not all in git) except the ones the web root keeps as its own.
copy_public_files() {
    local list f rel
    list="$STATE_DIR/public-files.list"
    (cd "$APP_DIR/public" && find . \( -path ./build -o -path "./build.*" -o -path ./storage -o -path ./hot \) -prune -o \
        \( -type f -o -type l \) -print0) > "$list" || return 1
    while IFS= read -r -d '' f; do
        rel="${f#./}"
        case "$rel" in
            index.php | .htaccess) continue ;; # the web root's own versions
        esac
        mkdir -p "$WEB_ROOT/$(dirname "$rel")" || return 1
        cp -pP "$APP_DIR/public/$rel" "$WEB_ROOT/$rel" || return 1
    done < "$list"
}

# Files added on the server outside git that the new version also contains:
# the switch takes them over, and going back would delete them. They are
# archived first and every rollback puts them back.
UNTRACKED_ARCHIVE=""
save_untracked_files() {
    local target="$1" list
    list="$STATE_DIR/untracked-taken-over.list"
    : > "$list"
    git -C "$APP_DIR" ls-files --others --exclude-standard -z > "$STATE_DIR/untracked.all" || return 1
    while IFS= read -r -d '' f; do
        if git -C "$APP_DIR" cat-file -e "$target:$f" 2>/dev/null; then
            printf '%s\0' "$f" >> "$list"
        fi
    done < "$STATE_DIR/untracked.all"
    if [ -s "$list" ]; then
        UNTRACKED_ARCHIVE="$STATE_DIR/untracked-$(date '+%Y%m%d-%H%M%S').tar"
        tar -C "$APP_DIR" --null -T "$list" -cf "$UNTRACKED_ARCHIVE" || return 1
        ok "$(tr -cd '\0' < "$list" | wc -c) fichier(s) ajouté(s) hors git sauvegardé(s) : $UNTRACKED_ARCHIVE"
    fi
}

save_web_root_build() {
    [ -n "$WEB_ROOT" ] || return 0
    rm -rf "$STATE_DIR/webroot-build.previous"
    if [ -d "$WEB_ROOT/build" ]; then
        cp -R "$WEB_ROOT/build" "$STATE_DIR/webroot-build.previous"
    fi
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
    # Shared hosting disables proc_open, which Composer needs to run the
    # project's scripts (@php artisan ...). Install without them, then run the
    # same steps (composer.json › post-autoload-dump) in-process.
    (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER[@]}" install \
        --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts) || return 1
    rm -f "$APP_DIR/bootstrap/cache/packages.php" "$APP_DIR/bootstrap/cache/services.php" || return 1
    artisan package:discover --ansi || return 1
    # grep without -q reads all the output (pipefail: no early close).
    if artisan list --raw 2>/dev/null | grep '^filament:upgrade' >/dev/null; then
        artisan filament:upgrade || return 1
    fi
    ok "Paquets PHP installés"
}

refresh_caches() {
    artisan optimize:clear >/dev/null || return 1
    artisan optimize || return 1
    ok "Caches reconstruits"
}

# Before maintenance: make sure Composer can bring vendor/ to the new
# composer.lock. Shared hosting disables proc_open, which Composer needs to
# add or remove packages; when nothing changes, it does not need it.
check_php_packages() {
    local target="$1" dry_run
    say "Paquets PHP"
    if ! dry_run="$(cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER[@]}" install --dry-run \
        --no-dev --no-interaction --no-scripts --no-progress 2>&1)"; then
        printf '%s\n' "$dry_run" | tail -n 15
        die "Composer ne peut pas vérifier les paquets PHP installés (voir ci-dessus). Rien n'a été modifié."
    fi
    if git -C "$APP_DIR" diff --quiet HEAD "$target" -- composer.lock composer.json \
        && printf '%s\n' "$dry_run" | grep 'Nothing to install, update or remove' >/dev/null; then
        ok "Paquets PHP déjà à jour : rien à télécharger"
        return 0
    fi
    if "${COMPOSER[0]}" -r 'exit(function_exists("proc_open") && ! in_array("proc_open", array_map("trim", explode(",", (string) ini_get("disable_functions"))), true) ? 0 : 1);' 2>/dev/null; then
        ok "Paquets PHP à mettre à jour : Composer peut les installer"
        return 0
    fi
    die "Cette version change les paquets PHP (composer.lock), mais ce serveur interdit à
Composer de les installer (proc_open est désactivé par l'hébergeur). Rien n'a été
modifié et le site reste en ligne. Voir docs/DEPLOIEMENT.md, « Paquets PHP »."
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
    if [ -n "$LOCAL_PATCH" ] && [ -s "$LOCAL_PATCH" ]; then
        # Files that had been edited by hand on the server come back too.
        git -C "$APP_DIR" apply --whitespace=nowarn "$LOCAL_PATCH" || return 1
        ok "Modifications locales du serveur remises ($LOCAL_PATCH)"
    fi
    if [ -n "$UNTRACKED_ARCHIVE" ] && [ -s "$UNTRACKED_ARCHIVE" ]; then
        tar -C "$APP_DIR" -xf "$UNTRACKED_ARCHIVE" || return 1
        ok "Fichiers ajoutés hors git remis ($UNTRACKED_ARCHIVE)"
    fi
    composer_install || return 1
    if [ -d "$STATE_DIR/build.previous" ]; then
        rm -rf "$APP_DIR/public/build" || return 1
        cp -R "$STATE_DIR/build.previous" "$APP_DIR/public/build" || return 1
        ok "Interface précédente remise"
    fi
    if [ -n "$WEB_ROOT" ]; then
        if [ -d "$STATE_DIR/webroot-build.previous" ]; then
            rm -rf "$WEB_ROOT/build" || return 1
            cp -R "$STATE_DIR/webroot-build.previous" "$WEB_ROOT/build" || return 1
        fi
        copy_public_files || return 1
        ok "Dossier public remis ($WEB_ROOT)"
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
        # -n rather than `| head`: with pipefail, head closing the pipe early
        # would end the script.
        git log --oneline -n 20 "$current..$target" | sed 's/^/      /'
        total="$(git rev-list --count "$current..$target")"
        if [ "$total" -gt 20 ]; then
            printf '      … et %s autres\n' "$((total - 20))"
        fi
    fi
    review_local_changes
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
    LOCAL_PATCH="$(cat "$STATE_DIR/previous-local-patch" 2>/dev/null || true)"
    UNTRACKED_ARCHIVE="$(cat "$STATE_DIR/previous-untracked-archive" 2>/dev/null || true)"
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
review_local_changes

prepare_assets "$TARGET"
check_php_packages "$TARGET"
backup_database

say "Mise à jour"
PREVIOUS="$CURRENT"
printf '%s\n' "$PREVIOUS" > "$STATE_DIR/previous-commit"
printf '%s\n' "$LOCAL_PATCH" > "$STATE_DIR/previous-local-patch"
save_untracked_files "$TARGET"
printf '%s\n' "$UNTRACKED_ARCHIVE" > "$STATE_DIR/previous-untracked-archive"
rm -rf "$STATE_DIR/build.previous"
[ -d "$APP_DIR/public/build" ] && cp -R "$APP_DIR/public/build" "$STATE_DIR/build.previous"
save_web_root_build

maintenance_on
STARTED=1

# Local edits were saved as a patch above; the rollback re-applies it.
git checkout -q -f -B "$BRANCH" "$TARGET"
git reset -q --hard "$TARGET"
ok "Code : $(git log -1 --format='%h %s')"

composer_install
install_assets
sync_web_root

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
