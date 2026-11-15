# Images légères

Extension WordPress qui convertit les images en WebP, retaille les originaux surdimensionnés,
harmonise les photos produit WooCommerce au ratio cible et sert la même photo aux flux
Google et Meta. Aucune dépendance réseau, aucune clé, aucun appel à un service externe.

## Installation

1. Copier le dossier dans `wp-content/plugins/images-legeres`.
2. Activer l'extension depuis l'écran « Extensions ».
3. Régler l'extension depuis **Médias → Images légères**.

À l'activation, l'extension crée sa table d'état et initialise ses réglages par défaut.
Aucun fichier n'est touché avant d'avoir explicitement lancé une conversion ou un lot.

## Réglages

Tous les réglages (qualité WebP, dimension maximale, ratio cible, plage horaire des lots,
etc.) vivent dans l'écran **Médias → Images légères**, encart Réglages. Le détail de chaque
réglage et sa valeur par défaut sont décrits dans l'écran lui-même.

Le **mode aperçu** est actif par défaut : la réécriture des adresses vers les fichiers
`.webp` et les sous-tailles recadrées ne s'applique qu'à un administrateur connecté. Les
visiteurs reçoivent les adresses d'origine jusqu'à ce que le mode aperçu soit désactivé
volontairement.

## Ligne de commande

```
wp images-legeres status [--format=table|json|yaml]
wp images-legeres orphelins [--format=table|json|csv|yaml]
```

`orphelins` liste ce qui resterait de l'extension après une désinstallation (table, options,
métas, drop-ins, dossier `uploads/images-legeres`) ; une liste vide signifie qu'il ne reste
rien.

## Retour en arrière

Trois niveaux, dans l'écran d'administration et dans l'écran « Extensions » :

- **Désactiver** : la réécriture s'arrête, le site revient à ses images d'origine en moins
  d'une minute. Rien n'est supprimé sur le disque ni en base.
- **Tout rendre** (bouton dans l'encart État) : restaure les originaux retaillés, supprime
  les fichiers générés (`.webp`, images de flux), puis désinstalle.
- **Désinstaller** (depuis l'écran « Extensions ») : supprime la table, les options et les
  métas de l'extension ; laisse les fichiers.

Aucun « drop-in » (`object-cache.php`, `advanced-cache.php`) n'est jamais posé.

## Développement

Environnement de test jetable (WordPress + WooCommerce + MariaDB, local, non publié) :

```
tests/env/up.sh
tests/env/down.sh
```

Tests : `tests/test-activation.sh` (exécuté dans le conteneur, voir ce fichier).

## Licence

GPL-2.0-or-later.
