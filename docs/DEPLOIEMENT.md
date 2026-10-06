# Mettre à jour le serveur en ligne (drclickdz.com)

Une seule commande, en SSH, met en ligne la dernière version :

```bash
bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh
```

C'est tout. Le script affiche chaque étape en français et s'arrête avec un
message clair si quelque chose ne va pas.

## Avant de lancer

1. **La nouvelle version doit être sur `main`** dans GitHub (la Pull Request
   est fusionnée).
2. **Attendez la fin de l'action GitHub « web-build »** (onglet *Actions* du
   dépôt, 3 à 5 minutes après la fusion). Elle compile l'interface, car
   l'hébergement Hostinger n'a pas Node.js. Si vous lancez le script trop
   tôt, il attend tout seul jusqu'à 15 minutes.

## Se connecter en SSH

hPanel › Avancé › **Accès SSH** donne l'utilisateur, l'adresse et le port
(Hostinger utilise le port 65002) :

```bash
ssh -p 65002 u165892118@<adresse-du-serveur>
```

## Ce que fait le script

| Étape | Détail |
|---|---|
| Vérifications | PHP 8.3+, Composer, git, `.env`, aucun fichier du code modifié à la main sur le serveur |
| Sauvegarde | Sauvegarde chiffrée de la base (`scripts/server/nightly-backup.sh`), avant tout changement |
| Maintenance | Le site affiche « en maintenance » (en général moins d'une minute) |
| Mise à jour | Nouveau code (git), paquets PHP (`composer install --no-dev`), interface compilée, migrations de la base, caches |
| Remise en ligne | Puis vérification que `https://drclickdz.com/up` répond |

**Si une étape échoue après la mise en maintenance**, le script remet tout
seul l'ancienne version (code, paquets, interface) et remet le site en
ligne. Les migrations déjà passées restent (elles ne font qu'ajouter des
tables ou des colonnes).

## Autres commandes

```bash
# Voir ce qui va changer, sans rien modifier
bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh --check

# Revenir à la version d'avant le dernier déploiement
bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh --rollback

# Déployer une autre branche (après « Run workflow » de web-build sur cette branche)
bash ~/domains/drclickdz.com/backend-laravel/scripts/server/deploy.sh ma-branche
```

Options (à mettre devant la commande, par exemple
`DRCLICK_SKIP_BACKUP=1 bash .../deploy.sh`) :

| Option | Effet |
|---|---|
| `DRCLICK_SKIP_BACKUP=1` | Pas de sauvegarde avant (si elle bloque) |
| `DRCLICK_FORCE=1` | Réinstalle même si c'est déjà à jour, ou écrase des fichiers modifiés à la main sur le serveur |
| `DRCLICK_PHP=/opt/alt/php83/usr/bin/php` | Si le script ne trouve pas PHP 8.3 tout seul |
| `DRCLICK_ASSETS=node` | Compiler l'interface sur le serveur au lieu de GitHub (Node.js 20+ requis ; plus lent, pendant la maintenance) |

Le journal complet est dans `~/.local/state/drclick-deploy/deploy.log`.

## Clé d'activation des postes (une seule fois)

Tant qu'elle n'est pas faite, le script le rappelle à la fin. Lancez :

```bash
bash ~/domains/drclickdz.com/backend-laravel/scripts/server/setup-activation-key.sh
```

Il crée la clé, l'ajoute au `.env` et affiche la **clé publique** : copiez
le texte de `-----BEGIN PUBLIC KEY-----` à `-----END PUBLIC KEY-----` et
envoyez-le pour qu'il soit ajouté à l'application de bureau. Gardez une
copie de la clé **privée** (`~/.config/drclick-keys/entitlement-private.pem`)
hors du serveur et ne la donnez à personne.

## Première installation (une seule fois)

Le script a besoin que le dossier du site soit un dépôt git relié à GitHub.
Vérifiez :

```bash
cd ~/domains/drclickdz.com/backend-laravel && git remote -v
```

**Si une adresse GitHub s'affiche**, tout est prêt. Le premier déploiement
se lance ainsi (le script n'est pas encore sur le serveur, on le prend dans
GitHub) :

```bash
cd ~/domains/drclickdz.com/backend-laravel
git fetch origin main
git show origin/main:scripts/server/deploy.sh > ~/drclick-deploy.sh
bash ~/drclick-deploy.sh
```

Ensuite, utilisez toujours la commande du début de cette page.

**Si « not a git repository » s'affiche**, le site a été copié sans git.
Il faut d'abord donner au serveur un accès en lecture au dépôt :

```bash
ssh-keygen -t ed25519 -N "" -f ~/.ssh/drclick_github -C "drclickdz.com deploy"
cat ~/.ssh/drclick_github.pub
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/drclick_github
  IdentitiesOnly yes
EOF
```

Collez la clé affichée dans GitHub › dépôt › *Settings* › *Deploy keys* ›
*Add deploy key* (sans cocher « Allow write access »). Puis reliez le
dossier à GitHub. Le site passe en maintenance pendant ce temps ; `.env`,
`storage/` et `vendor/` ne sont pas touchés, et une copie complète du
dossier est faite avant :

```bash
cd ~/domains/drclickdz.com
tar czf ~/backend-laravel-avant-git.tar.gz backend-laravel
cd backend-laravel
php artisan down
git init -q
git remote add origin git@github.com:hodaifayahia/cabinetMedical.git
git fetch origin main
git reset -q origin/main
git branch -M main
git checkout -- .
git branch --set-upstream-to=origin/main main
DRCLICK_FORCE=1 bash scripts/server/deploy.sh
```

La dernière ligne installe les paquets, l'interface et les migrations de
cette version, puis remet le site en ligne. Ensuite, utilisez toujours la
commande du début de cette page.

## En cas de problème

- **« Des fichiers du code ont été modifiés directement sur le serveur »** :
  quelqu'un a modifié des fichiers via le gestionnaire de fichiers. Si ces
  changements ne sont pas importants, relancez avec `DRCLICK_FORCE=1`.
- **« GitHub n'a pas (encore) compilé l'interface »** : ouvrez l'onglet
  *Actions* › *web-build* ; s'il est en rouge, relancez-le (*Re-run*).
- **Le site reste en maintenance** (rare) : `php artisan up` dans le dossier
  du site, puis `deploy.sh --rollback`.
- **Restaurer la base** : voir `docs/server-backups.md` › *Restore*.
