# Aide utilisateur — Configuration MediSmart

Cette aide décrit l'interface réellement disponible dans l'application Windows.
Certaines options restent grisées tant que le service local, la licence ou la
configuration de déploiement correspondante n'est pas vérifié.

## Première ouverture

Sur une installation vide, MediSmart demande une seule fois de créer le compte
propriétaire :

1. saisissez le nom complet du médecin ;
2. choisissez la spécialité médicale ;
3. saisissez l'adresse e-mail et un mot de passe fort ;
4. confirmez avec **Créer le compte propriétaire**.

La création initiale est ensuite fermée. Les autres comptes doivent être créés
par un administrateur et nécessitent la fonction de licence
`multi_user`. Si cette fonction n'est plus disponible, les comptes existants
restent visibles et administrables ; MediSmart refuse seulement l'ajout d'un
nouveau compte. La spécialité choisie est utilisée dans les documents et est
volontairement verrouillée après cette étape.

## Cabinet et documents

Ouvrez **Configuration > Cabinet & documents**. Renseignez les informations qui
doivent apparaître sur les documents pris en charge :

- nom du médecin ;
- numéro d'ordre ou identifiant professionnel ;
- nom du cabinet ;
- téléphone et e-mail professionnels ;
- ville et adresse complète ;
- ligne supplémentaire du pied de page ;
- logo du cabinet au format PNG, JPEG ou WebP.

L'aperçu du pied de page assemble automatiquement téléphone, e-mail, adresse et
ligne supplémentaire. Cliquez sur **Enregistrer**, puis vérifiez un nouveau
document généré. Les anciennes vues d'impression sont migrées progressivement :
un ancien écran peut ne pas encore utiliser le nouvel en-tête.

Le logo et la ligne supplémentaire du pied de page nécessitent la fonction de
licence `custom_branding` pour être ajoutés, modifiés ou supprimés. Sans cette
fonction, le nom du médecin, l'identifiant professionnel, le cabinet et ses
coordonnées restent modifiables. Un logo ou un pied de page déjà enregistré
reste visible et continue d'être utilisé dans les documents ; il n'est ni
masqué ni supprimé par un changement de licence.

La spécialité ne change pas avec le formulaire principal. Pour corriger une
erreur, le propriétaire doit utiliser **Correction administrative de la
spécialité**, confirmer son mot de passe, choisir la valeur, cocher la
confirmation volontaire puis enregistrer. Cette correction est journalisée.

## Comprendre les états

Dans **Configuration > Connexion & sauvegardes**, les cartes **État réel de
l'installation** indiquent ce que les services locaux observent. Elles sont
plus fiables qu'une préférence cochée ou que le simple état « navigateur
connecté ».

- **Disponible / Prêt** : toutes les conditions contrôlées sont présentes.
- **Arrêté** : la fonction est configurée ou possible, mais son service n'est
  pas actif.
- **Indisponible** : une condition obligatoire manque ; le texte sous l'option
  explique laquelle.
- **Dégradé / Erreur** : ne répétez pas l'action sans lire le message et les
  informations techniques.

Le bouton **Enregistrer** est désactivé quand aucune valeur modifiable n'a
changé. Pour un export, une restauration, Drive ou une action de licence,
cliquez d'abord sur **Confirmer mon mot de passe**.

## Réseau local et QR code

Pour recevoir un document depuis un téléphone sur le même réseau privé :

1. Dans **Réseau local**, activez **Activer la réception locale**.
2. Sélectionnez la carte réseau physique du cabinet. Évitez VPN, partage public
   et réseau invité isolé.
3. Enregistrez et attendez l'état **actif et vérifié**. MediSmart choisit un port
   sûr ; le port préféré n'est qu'une préférence.
4. Dans **Politique de téléversement**, choisissez **Réseau local**, une durée de
   validité de 1 à 30 minutes et les limites de fichiers/taille, puis enregistrez.
5. Dans la création de lien, choisissez le patient si demandé et cliquez sur
   **Créer le lien QR**.
6. Scannez le QR depuis le téléphone connecté au même réseau. Seuls PDF, JPEG et
   PNG sont acceptés.
7. Dans MediSmart, contrôlez chaque fichier en attente, puis acceptez-le ou
   rejetez-le. Aucun fichier n'entre directement dans le dossier clinique.
8. Révoquez la session quand l'envoi est terminé.

MediSmart ne modifie pas le pare-feu Windows. Si le diagnostic local réussit
mais que le téléphone ne se connecte pas, vérifiez le profil **Réseau privé** et
l'autorisation de l'application signée. Ne désactivez pas le pare-feu et
n'ouvrez pas de port sur le routeur.

Le mode **Tunnel distant** n'est sélectionnable que si la licence l'autorise et
si un tunnel nommé a été provisionné et vérifié par l'administrateur technique.
Le mode **Relais sécurisé** n'est pas disponible dans cette version : la
fonction de licence `remote_relay` est une condition nécessaire, mais aucun
service relais central n'est encore fourni. La présence de cette fonction dans
une licence ne crée donc pas de lien relais.

## Sauvegarde locale

Dans **Sauvegarde immédiate vérifiée** :

1. confirmez votre mot de passe ;
2. saisissez deux fois une phrase secrète d'au moins 12 caractères ;
3. cliquez sur **Créer l'archive chiffrée** ;
4. conservez le fichier `.msbackup` sur un support chiffré approuvé ;
5. conservez la phrase secrète séparément ; MediSmart ne la stocke pas et ne
   peut pas la récupérer ;
6. vérifiez l'état **Vérifiée** dans **Historique des sauvegardes**.

L'archive versionnée inclut la base SQLite cohérente, les documents gérés, les
logos, un manifeste et les sommes de contrôle. Si le chiffrement Sodium est
indisponible, une archive locale non chiffrée peut être proposée ; elle contient
des données cliniques et doit rester sur un support local sécurisé.

La sauvegarde automatique fonctionne seulement quand le scheduler supervisé
est actif. Configurez l'heure et les rétentions quotidienne, hebdomadaire et
mensuelle, enregistrez, puis contrôlez l'historique après l'heure prévue.

## Google Drive

Si l'écran affiche **Google Drive indisponible**, vérifiez le motif affiché. Il
peut manquer la configuration OAuth de l'installation, une licence compatible,
Sodium, la connexion internet ou le worker supervisé. L'utilisateur ne doit pas
ajouter lui-même des secrets dans `.env`.

Quand Drive est disponible :

1. confirmez votre mot de passe et cliquez sur **Connecter Google Drive** ;
2. autorisez le compte dans le navigateur système ;
3. revenez à MediSmart et testez la connexion ;
4. saisissez le dossier Drive et la phrase secrète de sauvegarde ;
5. lancez l'envoi et attendez la confirmation dans l'historique ;
6. utilisez la liste Drive pour actualiser, télécharger et vérifier une archive.
   La suppression n'est autorisée que si une autre archive gérée, strictement
   plus récente, existe encore sur Drive, correspond exactement à un envoi
   local terminé (identifiant, taille et SHA-256) et passe une seconde
   vérification de ses métadonnées.

Sans cette preuve d'une copie distante plus récente, MediSmart refuse la
suppression. Une suppression autorisée est définitive sur Drive et ne supprime
pas une éventuelle copie locale. **Déconnecter Google Drive** supprime les
identifiants OAuth locaux, mais conserve les fichiers déjà présents sur Drive.

## Restaurer une sauvegarde

La restauration brute d'un fichier SQLite n'est pas proposée. Utilisez
uniquement l'archive chiffrée `.msbackup` depuis l'application Windows :

1. terminez les consultations en cours ;
2. confirmez le mot de passe administrateur ;
3. choisissez l'archive et saisissez sa phrase secrète ;
4. cliquez sur **Vérifier l'archive** ;
5. contrôlez la date, la version MediSmart, le schéma, les composants, le nombre
   de fichiers et la taille ; aucune donnée active n'est encore modifiée ;
6. cochez la confirmation seulement si le contenu est correct ;
7. cliquez sur **Appliquer et redémarrer MediSmart** ;
8. n'éteignez pas l'ordinateur et ne fermez pas l'application ;
9. après redémarrage, vérifiez les patients récents, les documents, le logo et
   l'historique des sauvegardes.

MediSmart crée une sauvegarde de sécurité avant le remplacement et redémarre
seulement après vérification du service. Si **Récupération manuelle requise**
apparaît, ne relancez pas l'application, PHP ou les workers. Ne supprimez aucun
fichier de restauration/rollback et contactez le support autorisé.

## Licence MediSmart

L'activation nécessite une connexion internet et un serveur de licences déjà
configuré par le déploiement :

1. confirmez le mot de passe ;
2. saisissez le numéro de licence fourni ;
3. cliquez sur **Activer** ;
4. contrôlez l'édition, l'expiration et la date de dernière vérification.

**Actualiser** vérifie la licence en ligne. En cas d'avertissement d'horloge,
corrigez la date/heure Windows avant de réessayer. L'état **Grâce hors ligne**
permet l'usage prévu jusqu'à la date affichée, sans prétendre que les fonctions
internet sont disponibles.

Utilisez **Désactiver cet appareil** uniquement avant de déplacer volontairement
la licence vers un autre ordinateur et quand internet fonctionne. Si la
désactivation distante échoue, la licence locale reste inchangée. Une licence
expirée ne doit jamais servir à supprimer les données du cabinet ; gardez
l'accès aux opérations de sauvegarde et de récupération.

Les fonctions de licence ont une portée limitée :

- `multi_user` autorise l'ajout d'un compte, sans masquer les comptes
  existants ;
- `custom_branding` autorise la modification du logo et de la ligne
  supplémentaire, sans retirer le contenu déjà utilisé ;
- `remote_relay` ne remplace pas le service relais central, qui reste absent ;
- `automatic_updates` autorise la recherche automatique, pas le
  téléchargement automatique.

## Mises à jour

L'écran indique si la version Windows a été compilée avec le service de mise à
jour signée approuvé. Dans une version non configurée, les commandes restent
indisponibles et l'utilisation locale continue normalement. N'ajoutez pas
vous-même une adresse ou une clé dans `.env`.

Dans une version configurée :

1. utilisez **Rechercher maintenant** pour une vérification manuelle ;
2. si une version signée est proposée, confirmez votre mot de passe ;
3. choisissez **Sauvegarder et installer** ;
4. attendez la création et la vérification de la nouvelle archive
   `.msbackup` ; MediSmart lie l'autorisation à cette sauvegarde, à cet
   ordinateur et à la version proposée ;
5. laissez MediSmart vérifier la signature native puis redémarrer.

La recherche automatique nécessite `automatic_updates`. Le téléchargement
automatique reste désactivé : aucune option ne doit prétendre télécharger en
arrière-plan. Ne téléchargez jamais un exécutable depuis un lien non approuvé.
La publication et l'installation réelle restent soumises au canal officiel et
à la validation de la version Windows signée.

Au premier démarrage après une mise à niveau, MediSmart vérifie le contrat de
migration avant de lancer les services. Si des migrations sont nécessaires, il
crée d'abord une copie SQLite de sécurité vérifiée, journalise l'opération,
applique uniquement le lot prévu et contrôle la base. Un état de récupération
ambigu laisse l'application hors ligne : ne supprimez alors ni la base, ni le
journal, ni les copies de sécurité.

## Fermer ou quitter MediSmart

La croix de la fenêtre masque MediSmart dans la zone de notification Windows ;
elle n'arrête pas les services locaux. Cliquez ou double-cliquez sur l'icône,
ou choisissez **Ouvrir MediSmart**, pour réafficher la fenêtre. Pour arrêter
proprement MediSmart et ses services, choisissez **Quitter MediSmart** dans le
menu de cette icône. Le lancement automatique à l'ouverture de session n'est
pas disponible.

## Si « rien ne change »

Vérifiez dans cet ordre :

1. êtes-vous connecté avec un compte autorisé à gérer la configuration ?
2. avez-vous réellement modifié un champ avant de cliquer sur **Enregistrer** ?
3. un message de validation apparaît-il sous le champ ?
4. pour le LAN, l'état natif est-il **actif et vérifié** après l'enregistrement ?
5. pour Drive, la licence, le tunnel ou les mises à jour, le texte
   **Indisponible** indique-t-il une configuration de déploiement manquante ?
6. pour la spécialité, avez-vous utilisé la zone de correction administrative
   après confirmation du mot de passe ?
7. pour un nouvel utilisateur, un logo, le pied de page, un relais ou les
   recherches automatiques, la fonction de licence correspondante est-elle
   disponible ?
8. l'ancien document consulté appartient-il encore à une vue d'impression non
   migrée ? Créez un nouveau document pris en charge pour vérifier.

Ouvrez ensuite **État réel de l'installation > Informations techniques** et
utilisez **Copier les informations**. Ce résumé masque le vérificateur QR et
exclut les jetons OAuth/tunnel. Ne joignez jamais la base, un document patient,
une archive, une phrase secrète, un QR complet, un numéro de licence ou un
fichier de configuration secret à une demande d'assistance.
