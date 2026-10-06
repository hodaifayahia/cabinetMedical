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

## À faire une seule fois sur GitHub : l'action « web-build »

L'action qui compile l'interface doit être ajoutée à la main (pour des
raisons de sécurité, GitHub ne laisse pas un outil automatique créer une
action) :

1. Sur GitHub, dans le dépôt, choisissez la branche **main**, puis
   **Add file › Create new file**.
2. Nom du fichier : `.github/workflows/web-build.yml`
3. Collez tout le contenu de `scripts/server/web-build.workflow.yml`
   (ouvrez-le sur GitHub, bouton « Copy raw file »).
4. **Commit changes**. L'action se lance aussitôt (onglet *Actions*) et
   crée la branche `web-build-main` en 3 à 5 minutes.

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
| Mise à jour | Nouveau code (git), paquets PHP (`composer install --no-dev`), interface compilée (copiée aussi dans `public_html`), migrations de la base, caches |
| Remise en ligne | Puis vérification que `https://drclickdz.com/up` répond |

**Si une étape échoue après la mise en maintenance**, le script remet tout
seul l'ancienne version (code, paquets, interface, fichiers modifiés à la
main sur le serveur) et remet le site en ligne. Les migrations déjà passées restent (elles ne font qu'ajouter des
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

Le dossier du site doit être relié au dépôt GitHub (public : aucune clé
n'est nécessaire). Dans le dossier du site :

```bash
cd ~/domains/drclickdz.com/backend-laravel
git remote add origin https://github.com/hodaifayahia/cabinetMedical.git 2>/dev/null \
  || git remote set-url origin https://github.com/hodaifayahia/cabinetMedical.git
git fetch origin main
git show origin/main:scripts/server/deploy.sh > ~/drclick-deploy.sh
bash ~/drclick-deploy.sh --check
```

`--check` ne modifie rien. Il indique la version qui sera installée et
compare les fichiers modifiés à la main sur le serveur (anciens déploiements
faits fichier par fichier) avec l'historique de GitHub :

- **« déjà présent(s) dans GitHub »** : ces fichiers seront simplement
  remplacés par la nouvelle version ;
- **« n'existent PAS dans GitHub »** : ces changements n'existent que sur le
  serveur. Le script les liste et en garde une copie
  (`~/.local/state/drclick-deploy/local-changes-*.patch`). Vérifiez-les,
  puis lancez la mise à jour avec `DRCLICK_FORCE=1`.

Puis le premier déploiement :

```bash
bash ~/drclick-deploy.sh
# ou, si --check a signalé des changements propres au serveur :
DRCLICK_FORCE=1 bash ~/drclick-deploy.sh
```

Ensuite, utilisez toujours la commande du début de cette page.

**Le dossier public `public_html`.** Sur Hostinger, le site est servi depuis
`~/domains/drclickdz.com/public_html`, dont `index.php` charge
`../backend-laravel`. Le script le détecte : il y copie l'interface
(`build/`) et les fichiers publics, sans jamais toucher à `index.php`,
`.htaccess`, `storage`, `downloads`, `desktop-updates` ni aux autres
fichiers propres à ce dossier.

**Si `git` répond « not a git repository »** (site copié sans git) : faites
d'abord une copie du dossier, puis reliez-le à GitHub sans toucher à
`.env`, `storage/` ni `vendor/` :

```bash
cd ~/domains/drclickdz.com
tar czf ~/backend-laravel-avant-git.tar.gz backend-laravel
cd backend-laravel
php artisan down
git init -q
git remote add origin https://github.com/hodaifayahia/cabinetMedical.git
git fetch origin main
git reset -q origin/main
git branch -M main
git checkout -- .
git branch --set-upstream-to=origin/main main
DRCLICK_FORCE=1 bash scripts/server/deploy.sh
```

## Paquets PHP (Composer) sur Hostinger

L'hébergement désactive `proc_open` pour PHP. Composer en a besoin pour
lancer les scripts du projet et pour ajouter ou retirer des paquets. Le
script en tient compte :

- il installe avec `--no-scripts` puis lance lui-même `package:discover` et
  `filament:upgrade` avec `php artisan` (qui n'en a pas besoin) ;
- **avant** la maintenance, il vérifie que les paquets n'ont pas à changer
  (`composer.lock` identique, « Nothing to install »). Si une version change
  les paquets, il s'arrête sans rien toucher : le site reste en ligne.

Dans ce cas (rare : mise à jour de Laravel ou d'un paquet), préparez
`vendor/` sur un PC avec PHP 8.3 (`composer install --no-dev
--optimize-autoloader` dans une copie du projet à la nouvelle version),
envoyez-le sur le serveur à la place de `backend-laravel/vendor`, puis
relancez le script.

## En cas de problème

- **« Des fichiers du code ont été modifiés directement sur le serveur »** :
  quelqu'un a modifié des fichiers via le gestionnaire de fichiers. Si ces
  changements ne sont pas importants, relancez avec `DRCLICK_FORCE=1`.
- **« GitHub n'a pas (encore) compilé l'interface »** : ouvrez l'onglet
  *Actions* › *web-build* ; s'il est en rouge, relancez-le (*Re-run*).
- **Le site reste en maintenance** (rare) : relancez d'abord le script
  (`bash ~/drclick-deploy.sh`) ; il reprend là où il s'est arrêté. Sinon
  `deploy.sh --rollback`, et en dernier recours `php artisan up`.
- **Restaurer la base** : voir `docs/server-backups.md` › *Restore*.
