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
wp images-legeres convert <id> [--dry-run]
wp images-legeres run [--limite=<n>] [--forcer] [--format=table|json|yaml]
wp images-legeres qualite <id>... [--qualite=<n>] [--format=table|json|yaml|csv]
```

`orphelins` liste ce qui resterait de l'extension après une désinstallation (table, options,
métas, drop-ins, dossier `uploads/images-legeres`) ; une liste vide signifie qu'il ne reste
rien. `convert` traite une pièce jointe (conversion WebP, retaille si le réglage est actif).
`run` lance le lot hors serveur web (file Action Scheduler ou boucle WP-CLI synchrone),
refuse de tourner hors plage horaire sauf `--forcer`.

### Mesurer la perte : `qualite`

`qualite` mesure SSIM et PSNR entre l'original d'une pièce jointe et son WebP — la question
« la perte est-elle invisible ? » posée aux vraies photos du site plutôt qu'à des témoins de
laboratoire. C'est l'outil de recette à passer sur une production avant d'élargir la
conversion.

```
wp images-legeres qualite 12 13 14
wp images-legeres qualite 12 --qualite=95        # essaie un réglage sans rien écrire
```

Deux précautions, sans lesquelles le chiffre ne veut rien dire :

- **la comparaison se fait à dimensions égales.** Si le moteur a retaillé l'original avant de
  le convertir, comparer l'original d'avant retaille au WebP d'après mesure la retaille ET la
  compression, et impute à la seconde ce que la première a coûté (sur une photo produit
  2560 px ramenée à 1920 px, la retaille seule coûte déjà 0,002 de SSIM). La colonne
  `source_retaillee` dit quand la normalisation a joué ;
- **la source est presque toujours déjà compressée.** Un JPEG de site est une image avec
  perte : le SSIM mesuré est celui d'une deuxième génération de perte, pas celui d'un WebP
  face à un original d'appareil photo. Un SSIM de 0,975 sur un JPEG de 2560 px n'a pas le
  même sens que sur un PNG d'aplats.

Sans `--qualite`, la commande lit le fichier `.webp` réellement présent. Avec `--qualite`,
elle réencode dans un fichier temporaire, mesure et efface : rien n'est écrit à côté de
l'image.

### Interfaces usine (recadrage et textes alternatifs)

Trois sous-commandes, en lecture/écriture limitée, pour piloter depuis l'extérieur ce que
l'extension ne décide jamais elle-même (recadrage produit, textes alternatifs) — voir
« Développement » ci-dessous pour le détail des règles (idempotence, refus motivé) :

```
wp images-legeres crop get <id> [--format=<format>]
wp images-legeres crop set <id> --x=<x> --y=<y> --w=<w> --h=<h> [--ref=<largeurxhauteur>] [--source=<source>] [--format=<format>]
wp images-legeres crop clear <id> [--format=<format>]
wp images-legeres crop import <fichier.json> [--format=<format>]
wp images-legeres crop pending [--format=<format>]
wp images-legeres alt missing [--format=<format>]
wp images-legeres flux check [--format=<format>]
```

`crop pending` liste les images produit hors ratio cible sans rectangle ni marque « fond
assumé » ; `crop import` pose un lot de rectangles depuis un fichier JSON (une entrée invalide
est refusée seule, rien n'est écrit pour elle, code de sortie non nul si au moins un échec).
`alt missing` liste les pièces jointes affichées sans texte alternatif — l'extension ne
l'écrit jamais, poser le texte se fait avec `wp post meta update <id> _wp_attachment_image_alt
"<texte>"`. `flux check` liste les images produit dont le carré de flux ferait moins de
500 px de côté.

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

Tests : `tests/test-activation.sh` (exécuté dans le conteneur, voir ce fichier),
`tests/test-moteur.sh`, `tests/test-recadrage.sh`, `tests/test-cli-interfaces.sh`,
`tests/test-flux.sh`, `tests/test-lots.sh`.

**Chaque suite veut un WordPress jetable neuf** (`tests/env/up.sh` sur des volumes vides).
Elles comptent des pièces jointes et des lignes de file : enchaînées sur la même instance,
elles échouent sur leurs comptages sans qu'aucun défaut du produit soit en cause. Chaque
suite génère ou installe elle-même ce dont elle a besoin (témoins, extensions tierces).

Calibrage de la qualité WebP (spec § 7) :

```
wp eval-file tests/fixtures/generer-aplats.php <dossier>/aplats
wp eval-file tests/mesure-balayage.php <dossier-du-corpus> 80,85,88,90,92,95,98
```

Le corpus attend un sous-dossier par famille d'images (`produit/`, `ambiance/`, `aplats/`).
Le TSV produit donne SSIM, PSNR et poids par image et par qualité. Les photos réelles d'un
client ne sont jamais commitées : `tests/fixtures/corpus-f2/` est ignoré par git.

## Licence

GPL-2.0-or-later.
