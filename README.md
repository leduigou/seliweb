# Seliweb-WP

Extension WordPress libre et gratuite pour gérer un **S.E.L.** (Système d'Échange Local) : annonces, membres, transactions en monnaie(s) locale(s), cotisations, abonnements, événements.

Seliweb-WP fonctionne avec n'importe quel thème WordPress, mais s'accompagne du thème dédié [**Seliweb-view**](https://github.com/leduigou/Seliweb-view), qui reprend l'habillage graphique d'origine.

Le manuel utilisateur complet se trouve dans [`docs/Manuel-Utilisateur-Seliweb-WP.odt`](docs/Manuel-Utilisateur-Seliweb-WP.odt).

## Prérequis

- WordPress 6.0 ou supérieur
- PHP 7.4 ou supérieur

WordPress vérifie ces prérequis à l'activation et refuse l'activation si l'un des deux n'est pas satisfait.

## Installation

1. Téléchargez le zip de la [dernière release](https://github.com/leduigou/seliweb/releases/latest) (`seliweb-X.Y.Z.zip`).
2. Dans l'administration WordPress : **Extensions → Ajouter une extension → Téléverser une extension**, sélectionnez le zip téléchargé, puis **Installer**.
3. Activez l'extension : le menu **Seliweb** apparaît dans la colonne de gauche.

À l'activation, Seliweb-WP crée automatiquement ses tables en base, ses réglages par défaut, ses pages front-end (Annonces, Connexion, S'inscrire, Mon compte, Contact, Événements) et un menu de navigation. Aucune de ces étapes ne nécessite d'intervention manuelle.

Pour le rendu d'origine, installez également le thème [Seliweb-view](https://github.com/leduigou/Seliweb-view/releases/latest) (même procédure, dans **Apparence → Thèmes**) et activez-le.

### Attention avant de désactiver l'extension

Désactiver Seliweb-WP supprime ses pages front-end et son menu de navigation — **aucune donnée n'est perdue** (tout reste dans les tables), et pages/menu sont recréés à la réactivation. Un message vous le rappelle avant toute désactivation. Si le menu a été modifié à la main entre-temps, ces modifications ne sont pas restaurées.

## Mises à jour

Les nouvelles versions sont publiées sur ce dépôt GitHub et détectées automatiquement par WordPress (Extensions / Mises à jour), comme pour une extension classique du répertoire officiel.

## Configuration

Une fois activé, retrouvez tous les réglages dans **Seliweb → Paramètres** (onglets Inscription, Monnaies, Groupes, Annonces, Mails, SEL, Cotisations, Abonnements, API). Voir le manuel utilisateur pour le détail de chaque onglet.

## Changelog

### 1.0.3
- Correctif de sécurité (signalé par un testeur) : un compte créé via la page d'inscription native de WordPress (`wp-login.php?action=register`) échappait au formulaire d'inscription Seliweb, et pouvait publier des annonces sans restriction s'il n'était rattaché à aucun groupe. Corrigé en deux temps : la page d'inscription native redirige désormais systématiquement vers l'inscription Seliweb, et un membre sans groupe ne peut plus créer d'annonce même par un autre moyen.

### 1.0.2
- Événements → Synthèse des inscriptions : nouveau bouton « Ajouter une inscription », pour inscrire un membre manuellement (et répondre aux questions pour lui) depuis le back-office, sans passer par « Mon compte ». Sans restriction de groupe ni de date contrairement à l'inscription en front-end.

### 1.0.1
- Correctif de mise à jour automatique : la détection de la dernière version se fiait au premier fichier joint à la release GitHub, alors qu'un autre fichier (ex. le manuel en PDF) peut apparaître avant le zip de l'extension — WordPress tentait alors d'installer ce fichier comme s'il s'agissait du paquet, et échouait avec « PCLZIP_ERR_BAD_FORMAT : Unable to find End of Central Dir Record signature ». Corrigé en repérant explicitement le fichier `.zip`, quel que soit son ordre parmi les fichiers joints.

### 1.0.0
- Première version stable publiée.
- Gestion complète des annonces (catégories, rubriques, statuts, photos, prix libre/don), des membres (groupes, archivage RGPD, consentements tracés), des transactions SEL en monnaie(s) locale(s), des cotisations et abonnements (avec synchronisation HelloAsso et Paheko), des événements.
- Page Annonces et son affichage indépendants du thème actif.
- Prérequis WordPress/PHP annoncés à l'activation.
