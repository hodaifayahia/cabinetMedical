# Travailler à plusieurs PC sur la même base (réseau local)

Ce guide explique comment le PC du médecin et le PC de l’assistante utilisent
**les mêmes dossiers patients**, sur le réseau du cabinet, **sans Internet**.

- **Poste principal** : le PC du médecin. Il garde la base de données du
  cabinet (sur son disque) et la partage sur le réseau du cabinet.
- **Poste secondaire** : le PC de l’assistante (ou un autre PC). Il n’a pas sa
  propre base : il affiche celle du poste principal.

Aucun serveur à installer, aucun abonnement : les deux PC ont simplement
l’application Drclick.

## Avant de commencer

- Les deux PC sont reliés au **même réseau** (même box, même switch ou même
  Wi-Fi protégé par mot de passe).
- Le réseau Windows est de type **Privé** sur le poste principal :
  Paramètres › Réseau et Internet › Ethernet (ou Wi-Fi) › votre réseau ›
  Type de profil réseau › **Privé**.
- Le compte de l’assistante sera créé par le médecin (étape 2). Par défaut, un
  cabinet a droit à 2 comptes : le médecin + 1 utilisateur.

## 1. Sur le PC du médecin (poste principal)

1. Ouvrez Drclick et connectez-vous.
2. Allez dans **Configuration › Réseau local**.
3. Cliquez sur **Partager ce PC sur le réseau du cabinet**.
4. Si Windows affiche « Le Pare-feu Windows Defender a bloqué certaines
   fonctionnalités », cochez **Réseaux privés** puis cliquez sur
   **Autoriser l’accès**. Vous pouvez aussi cliquer sur **Autoriser Drclick
   dans le pare-feu** (Windows demande alors le mot de passe administrateur).
5. Notez l’**adresse affichée en grand**, par exemple
   `http://192.168.1.10:47850/`.

Le partage reste actif aux démarrages suivants, tant que vous ne l’arrêtez pas.

## 2. Créer le compte de l’assistante

Sur le poste principal : **Personnel › Ajouter** (nom, e-mail, mot de passe,
rôle). C’est ce compte qu’elle utilisera sur son PC.

## 3. Sur le PC de l’assistante (poste secondaire)

1. Installez Drclick.
2. Sur l’écran de connexion, cliquez sur
   **Plusieurs PC au cabinet ? Rejoindre le poste principal**.
3. Cliquez sur **Rechercher sur le réseau** puis choisissez le PC du médecin,
   ou saisissez l’adresse notée à l’étape 1 (par exemple `192.168.1.10`) et
   cliquez sur **Se connecter**.
4. Drclick redémarre et affiche « Connecté au poste principal 192.168.1.10 ».
5. L’assistante se connecte avec **son e-mail et son mot de passe** : elle voit
   les mêmes patients, rendez-vous et consultations que le médecin, selon les
   droits de son rôle.

## Au quotidien

- **Laissez Drclick ouvert sur le PC du médecin.** Fermer la fenêtre le garde
  actif dans la zone de notification (près de l’horloge) ; seul « Quitter »
  l’arrête. Si le poste principal est éteint, le poste secondaire affiche
  « Le serveur mémorisé ne répond pas » : allumez le PC du médecin, ouvrez
  Drclick, puis cliquez sur **Réessayer**.
- **Internet n’est pas nécessaire.** Seules l’IA, la dictée vocale, la copie
  Google Drive et la synchronisation avec l’application mobile en ont besoin.
- **Sauvegardes** : elles se font sur le poste principal, qui détient les
  données.
- **Microphone** : la dictée fonctionne aussi sur le poste secondaire.

## Revenir en arrière

- Sur le poste secondaire : **Revenir au mode autonome** (écran de connexion,
  Configuration › Réseau local, ou l’écran « serveur ne répond pas »). Le PC
  reprend **sa propre** base, distincte de celle du médecin ; rien n’est
  fusionné.
- Sur le poste principal : **Configuration › Réseau local › Arrêter le
  partage**. Les postes secondaires ne peuvent plus travailler jusqu’à la
  réactivation.

## En cas de problème

| Symptôme | Que faire |
| --- | --- |
| « Aucun poste principal trouvé » | Saisissez l’adresse à la main. La recherche automatique peut être bloquée par le pare-feu ou par certains Wi-Fi. |
| « Le serveur ne répond pas » | Vérifiez que le PC du médecin est allumé, Drclick ouvert, et que le partage est actif (Configuration › Réseau local). |
| Ça marchait puis plus rien | L’adresse IP du PC du médecin a peut-être changé (box redémarrée). Relisez-la dans Configuration › Réseau local, ou demandez à votre installateur de lui réserver une adresse fixe dans la box. |
| Rien ne passe malgré tout | Réseau Windows en « Public » : passez-le en **Privé**, puis cliquez sur **Autoriser Drclick dans le pare-feu**. |
| « Le port 47850 est peut-être déjà utilisé » | Un autre programme utilise ce port : redémarrez le PC du médecin, puis réactivez le partage. |

## Sécurité

- Seuls les PC du **réseau privé** du cabinet peuvent se connecter ; les accès
  depuis Internet sont refusés, même si la box redirige le port.
- Chaque personne se connecte avec **son propre compte** et ses propres droits.
- Les échanges entre les PC ne sont **pas chiffrés** : utilisez un câble ou un
  Wi-Fi protégé par mot de passe, et ne partagez pas ce réseau avec les
  patients (utilisez un Wi-Fi « invités » séparé pour eux).
