#!/usr/bin/env bash
# One-time setup of the key that signs desktop licences, on the online server.
#
#   bash ~/domains/drclickdz.com/backend-laravel/scripts/server/setup-activation-key.sh
#
# - Creates an RSA key pair in ~/.config/drclick-keys (private key mode 600),
#   unless one is already there: running it again never replaces the key.
# - Sets MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH in the app's .env and
#   reloads the configuration.
# - Prints the PUBLIC key. It goes into the desktop app at
#   config/licensing/entitlement-public.pem (safe to share and to commit).
#   The PRIVATE key never leaves this server: keep one offline copy of it
#   (USB key, password manager). Lose it and desktops built with the old
#   public key can no longer be activated online.
set -euo pipefail
umask 077

APP_DIR="${DRCLICK_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
KEY_DIR="${DRCLICK_KEY_DIR:-$HOME/.config/drclick-keys}"
PRIVATE_KEY="$KEY_DIR/entitlement-private.pem"
PUBLIC_KEY="$KEY_DIR/entitlement-public.pem"
PHP_BIN="${DRCLICK_PHP:-php}"

die() { printf '\nERREUR : %s\n' "$*" >&2; exit 1; }

[ -f "$APP_DIR/.env" ] || die "Fichier .env introuvable dans $APP_DIR (DRCLICK_APP_DIR ?)"
command -v openssl >/dev/null 2>&1 || die "openssl est introuvable sur ce serveur."

mkdir -p "$KEY_DIR"
chmod 700 "$KEY_DIR"

if [ -f "$PRIVATE_KEY" ]; then
    echo "Une clé existe déjà : $PRIVATE_KEY (conservée)."
else
    openssl genrsa -out "$PRIVATE_KEY" 3072 2>/dev/null
    echo "Nouvelle clé créée : $PRIVATE_KEY"
fi
chmod 600 "$PRIVATE_KEY"
umask 022
openssl rsa -in "$PRIVATE_KEY" -pubout -out "$PUBLIC_KEY" 2>/dev/null

# The web server must be able to read it with the PHP that runs the site.
"$PHP_BIN" -r '
    $key = openssl_pkey_get_private(file_get_contents($argv[1]));
    $details = $key ? openssl_pkey_get_details($key) : false;
    exit($details && $details["type"] === OPENSSL_KEYTYPE_RSA && $details["bits"] >= 2048 ? 0 : 1);
' "$PRIVATE_KEY" || die "PHP ne peut pas lire la clé $PRIVATE_KEY."

# MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH=... in .env (replaced or added).
cp "$APP_DIR/.env" "$APP_DIR/.env.before-activation-key"
if grep -q '^MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH=' "$APP_DIR/.env"; then
    sed -i "s|^MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH=.*|MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH=$PRIVATE_KEY|" "$APP_DIR/.env"
else
    printf '\n# Signs the licence an installed desktop receives (scripts/server/setup-activation-key.sh).\nMEDISMART_ENTITLEMENT_SIGNING_KEY_PATH=%s\n' "$PRIVATE_KEY" >> "$APP_DIR/.env"
fi
echo ".env mis à jour (copie de l'ancien : .env.before-activation-key)"

if [ -f "$APP_DIR/bootstrap/cache/config.php" ]; then
    "$PHP_BIN" "$APP_DIR/artisan" config:cache >/dev/null
else
    "$PHP_BIN" "$APP_DIR/artisan" config:clear >/dev/null
fi
echo "Configuration rechargée."

cat <<EOF

=====================================================================
 C'est fait. Il reste deux choses :

 1. Gardez une copie de la clé PRIVÉE hors du serveur (clé USB,
    gestionnaire de mots de passe), et ne la donnez à personne :
      $PRIVATE_KEY

 2. Copiez la clé PUBLIQUE ci-dessous (de BEGIN à END compris) et
    envoyez-la pour qu'elle soit ajoutée à l'application de bureau
    (config/licensing/entitlement-public.pem). Elle peut être partagée.
=====================================================================

EOF
cat "$PUBLIC_KEY"
