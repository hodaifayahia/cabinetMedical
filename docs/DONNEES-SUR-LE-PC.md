# Les dossiers patients restent sur le PC du cabinet

Drclick enregistre les patients, consultations, ordonnances, documents et
paiements **uniquement sur le PC du cabinet** (application Windows). Le site
drclickdz.com ne les gère plus.

## Ce que fait chaque partie

| Partie | Rôle | Données de patients |
|---|---|---|
| Application Windows (PC du cabinet) | Tout le travail clinique, hors ligne | Toutes, sur le PC |
| drclickdz.com | Compte, licence, crédits IA, utilisateurs, application mobile | Aucun dossier médical |
| Application mobile | Prise de rendez-vous | Nom, téléphone et rendez-vous des patients qui réservent, transmis au PC |
| Assistant IA | Aide au médecin | Contenu médical **sans** nom, téléphone ni adresse. Images et voix seulement si le cabinet l'autorise |

Pour l'assistant IA, le médecin choisit dans **Configuration › Service en
ligne › Assistant IA** s'il envoie aussi les images (ECG, documents scannés)
et la voix (dictée). Désactivé : seul le texte part.

## Un cabinet qui utilisait le site en ligne

Ses dossiers sont encore sur le serveur. Sur le site, la page **Espace
cabinet** indique combien il en reste. Pour les récupérer sur le PC :

1. Installez la dernière version de l'application (0.4.0 ou plus récente).
2. **PC déjà installé en mode en ligne** : ouvrez Drclick, page *Espace
   cabinet*, bouton **« Utiliser ce PC hors ligne »**, confirmez. Drclick
   redémarre sur le PC.
   **Nouvelle installation** : rien à faire, elle démarre hors ligne.
3. Choisissez **« Cabinet existant »**. Saisissez l'e-mail du médecin
   titulaire dans les deux champs, son mot de passe du site, et laissez
   cochée **« Récupérer les dossiers enregistrés en ligne »**.
4. La copie se fait en arrière-plan (barre de progression). Chaque fichier
   est vérifié par empreinte, chaque table est recomptée.
5. À la fin, tapez **SUPPRIMER** pour effacer la copie du serveur.
   Le serveur ne garde que le nom, le téléphone des patients et leurs
   rendez-vous, pour l'application mobile.
6. Connectez-vous avec le même e-mail et le même mot de passe que sur le
   site. Les autres comptes du cabinet aussi.

Si quelque chose échoue, rien n'est effacé du serveur : bouton
**Réessayer**.

Ensuite, **faites des sauvegardes** : les dossiers n'existent plus que sur
ce PC (Configuration › Connexion & sauvegardes › Emplacement des
sauvegardes : clé USB, second disque…).

## Réglage du serveur

Par défaut, le site ferme les écrans de patients. Pour les rouvrir
temporairement (le temps que tous les cabinets passent sur leur PC), ajoutez
au `.env` du serveur :

```
MEDISMART_HOSTED_CLINICAL=true
```

puis `php artisan config:cache`. Retirez la ligne une fois les transferts
faits.

## Ce qui reste à vérifier côté réglementation

Le serveur (Hostinger) et le fournisseur d'IA sont à l'étranger. Les
réservations mobiles et les demandes à l'assistant IA y transitent encore.
Faites vérifier par un juriste ce que la loi 18-07 exige pour ces flux
(autorisation de l'ANPDP, information ou consentement des patients).
