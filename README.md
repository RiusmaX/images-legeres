# Images légères

Extension WordPress qui convertit les images en WebP, retaille les originaux surdimensionnés,
harmonise les photos produit WooCommerce au ratio cible et sert la même photo aux flux
Google et Meta. Aucune dépendance réseau, aucune clé, aucun appel à un service externe.

**Version 0.2.3.** Requiert WordPress 6.0 et PHP 8.1.

## Installation

1. Copier le dossier dans `wp-content/plugins/images-legeres`.
2. Activer l'extension depuis l'écran « Extensions ».
3. Régler l'extension depuis **Médias → Images légères**.

À l'activation, l'extension crée sa table d'état, initialise ses réglages par défaut et pose
la protection d'accès de ses dossiers (voir ci-dessous). Aucun fichier n'est touché avant
d'avoir explicitement lancé une conversion ou un lot.

## Fichiers produits et protection d'accès

L'extension écrit dans `wp-content/uploads` :

| Chemin | Contenu | Servi publiquement |
|---|---|---|
| `<nom>.jpg.webp`, `<nom>.png.webp` | frères WebP de l'original et de chaque sous-taille | oui — c'est l'objet de l'extension |
| `<nom>-il-harmonise-<L>x<H>.<ext>` | sous-tailles produit ramenées au ratio cible | oui, par la réécriture d'adresse |
| `images-legeres/flux/` | images JPEG carrées des flux Google et Meta | **oui**, obligatoirement : les deux plateformes doivent les télécharger |
| `images-legeres/originaux/` | copie pleine résolution des originaux retaillés | **non** — refusée par `.htaccess` |
| `images-legeres/journal-<date>.log` | trace des conversions | **non** — refusée par `.htaccess` |

Les règles de refus sont posées à l'activation, sans condition, et re-posées par tout code
qui crée un de ces dossiers : un `.htaccess` effacé à la main revient au passage suivant.
Elles sont écrites en syntaxe Apache 2.4 (`Require all denied`) avec le bloc Apache 2.2 en
repli, pour ne pas dépendre de `mod_access_compat`.

**Suppression d'une photo** : supprimer définitivement une pièce jointe depuis la médiathèque
retire aussi tout ce que l'extension avait produit pour elle — frères `.webp` de l'original et
de chaque sous-taille, copie de `images-legeres/originaux/`, image de flux, lignes d'état.
Rien ne reste en ligne à une adresse dérivable de l'adresse d'origine.

## Harmonisation des photos produit

Une boutique dont les photos n'ont pas toutes le même cadrage donne une grille de catalogue
irrégulière. L'extension produit, **à côté** des sous-tailles de WordPress et sans jamais
les remplacer, une sous-taille au ratio cible par recadrage sur le rectangle posé ou par
ajout d'un fond, nommée `<nom>-il-harmonise-<L>x<H>.<ext>`.

Trois conséquences voulues :

- **aucun fichier natif n'est touché** : WordPress et WooCommerce gardent la main sur leurs
  sous-tailles, et l'harmonisée n'entre pas dans `_wp_attachment_metadata['sizes']` ;
- l'harmonisée n'arrive sur une page que par la **réécriture d'adresse**, donc elle passe
  par la même garde que le WebP : **mode aperçu actif = administrateurs connectés seuls** ;
- désactiver l'extension suffit à revenir aux images d'origine, sans rien restaurer.

L'encodage JPEG suit `jpeg_quality` au lieu d'hériter de la qualité de la source : c'est ce
qui faisait peser 258 Ko à une vignette de 1024 px en production.

**Le JPEG harmonisé et son frère `.webp` sortent de la même qualité**
(`ImagesLegeres_Harmonisation::qualite()`). Les encoder séparément — 82 pour le JPEG,
`qualite_webp_produit` pour le WebP — produisait un WebP 4 à 5 % plus lourd que son JPEG,
donc refusé et supprimé comme « webp plus lourd que l'original ». À qualité égale, le WebP
est 21 à 28 % plus léger. Les profils EXIF et XMP de l'original ne sont plus recopiés dans
chaque harmonisée (environ 81 Ko par fichier) ; **le profil couleur ICC est conservé**.

### Zoom et visionneuse de la fiche produit

La galerie de WooCommerce n'agrandit pas le `src` : jQuery Zoom lit `data-src`, PhotoSwipe
lit `data-large_image` et ses dimensions déclarées, et retombe sur le `href` du lien parent.
Quand une balise de galerie est harmonisée, la réécriture aligne ces quatre valeurs sur la
**plus grande harmonisée de la pièce jointe qui ne dépasse pas 1 536 px de côté**, pour que
l'agrandissement montre la photo affichée et pas un autre cadrage. Les dimensions déclarées
sont celles du fichier réellement servi, mesurées sur le disque. Une balise sans harmonisée
n'est pas touchée.

**Le plafond de 1 536 px** (`ImagesLegeres_Harmonisation::COTE_GRANDE_MAX`) évite de servir
le `2048x2048` de WordPress — 841 Ko — à une visionneuse qui, sur un téléphone, n'affiche
jamais plus de 1 536 px de côté : le `1536x1536` de la même photo pèse 577 Ko, et 417 Ko une
fois les profils retirés. Au-delà, l'octet supplémentaire ne se voit nulle part ; en deçà,
l'agrandissement perdrait en définition. Si la pièce jointe n'a aucune harmonisée sous le
plafond, la plus petite au-dessus est gardée — mieux vaut une grande trop grande que le
rectangle natif, qui est un autre cadrage que la photo affichée.

Si aucune taille déclarée au ratio cible n'est assez large pour l'agrandissement,
`harmoniser` produit en plus la taille `images_legeres_grande` : le plus grand carré que
l'original donne **sans agrandir un seul pixel** (le rectangle posé en pixels, ou le plus
grand côté de l'original en fond ajouté). Les tailles carrées déclarées par WordPress
(`1536x1536`, `2048x2048`) suffisent dans la plupart des cas, et rien n'est alors produit en
plus. Après une mise à jour, régénérer :

```
wp images-legeres harmoniser
```

## Réglages

Tous les réglages (qualité WebP, dimension maximale, ratio cible, plage horaire des lots,
etc.) vivent dans l'écran **Médias → Images légères**, encart Réglages. Le détail de chaque
réglage et sa valeur par défaut sont décrits dans l'écran lui-même.

Le **mode aperçu** est actif par défaut : la réécriture des adresses vers les fichiers
`.webp` et les sous-tailles recadrées ne s'applique qu'à un administrateur connecté. Les
visiteurs reçoivent les adresses d'origine jusqu'à ce que le mode aperçu soit désactivé
volontairement.

Deux valeurs par défaut méritent d'être connues :

- **`gain_minimal_pourcent` vaut 0** (décision du 2026-11-15) : toute image a son WebP. Le
  seuil ne sert plus qu'à écarter le cas où le WebP pèserait **plus lourd** que l'original ;
  le JPEG reste alors servi, et `wp images-legeres status` le compte
  (`fichiers_webp_plus_lourd`). Un site installé avant cette décision est migré au premier
  chargement de l'extension après la mise à jour (cf. « Migrations de réglages »). C'est
  l'ancien seuil de 10 % qui avait laissé 87 sous-tailles sans WebP en production.
- **`ssim_imagick_actif` est désactivé** : le SSIM est toujours celui calculé par
  l'extension sur la **luminance**. `Imagick::compareImages()` moyenne sur les canaux RVB et
  rend une valeur plus basse (0,99399 contre 0,99738 sur la même paire) : le même seuil ne
  voudrait pas dire la même chose d'un hébergement à l'autre.

### Migrations de réglages

Une mise à jour peut changer la valeur par défaut d'un réglage. Un site déjà installé garde
l'option telle qu'elle a été enregistrée : il faut donc la migrer.

Ces migrations **ne dépendent pas du numéro de version** de l'extension. Elles portent un
nom, et chaque site retient ceux qu'il a déjà joués, dans la clé `migrations_appliquees` de
l'option `images_legeres_reglages` — pas dans une option à part, qui ressortirait en
orphelin après « Tout rendre ». Le registre est consulté à chaque chargement de l'extension ;
l'option étant autoloadée, cela ne coûte aucune requête, et rien n'est écrit tant qu'aucune
migration ne manque.

Pourquoi pas le numéro de version : la mise à jour du 2026-11-15 en production portait le
même `0.1.0` avant et après. La comparaison de version ne voyait rien, la migration du seuil
de gain n'est jamais partie, et il a fallu la passer à la main. Une mise à jour déposée par
Git ou par archive ne change pas forcément le numéro ; s'y fier, c'est parier sur la
discipline de celui qui publie.

Ajouter une migration : une entrée nommée dans `ImagesLegeres_Reglages::migrations()`, qui
reçoit le tableau des réglages enregistrés et rend le tableau modifié (ou `null` en cas
d'échec, pour être retentée). Le même registre sert aux migrations de schéma. Elle doit être
**idempotente** — elle peut être rejouée sur un site où son effet est déjà acquis.

## Traitement automatique des nouvelles images

Une image envoyée (ou dont les sous-tailles changent) est traitée en arrière-plan par Action
Scheduler, environ une minute plus tard, une image par action, **sans attendre la plage
horaire** et quel que soit l'état du lot manuel (« Pause » ne l'arrête pas). Le seuil de
charge s'applique : au-delà, le passage est reporté. Une image posée en image principale ou
en galerie d'un produit ou d'une variation est de plus harmonisée si l'harmonisation produit
est active. Une harmonisée à jour n'est pas réécrite ; après un changement de rectangle de
recadrage, lancer `wp images-legeres harmoniser <id>`. La plage horaire continue de régir les
lots lancés à la main. Le passage dépend de WP-Cron (ou d'un cron système).

## Ligne de commande

```
wp images-legeres status [--format=table|json|yaml]
wp images-legeres orphelins [--format=table|json|csv|yaml]
wp images-legeres convert <id> [--dry-run]
wp images-legeres run [--limite=<n>] [--forcer] [--format=table|json|yaml]
wp images-legeres qualite <id>... [--qualite=<n>] [--format=table|json|yaml|csv]
wp images-legeres harmoniser [<id>...] [--webp-manquants] [--dry-run]
```

`orphelins` liste ce qui resterait de l'extension après une désinstallation (table, options,
métas, drop-ins, dossier `uploads/images-legeres`, et frères `.webp` encore présents dans
`uploads`) ; une liste vide signifie qu'il ne reste rien. `convert` traite une pièce jointe (conversion WebP, retaille si le réglage est actif).
`run` lance le lot hors serveur web (file Action Scheduler ou boucle WP-CLI synchrone),
refuse de tourner hors plage horaire sauf `--forcer`.
`harmoniser` produit les sous-tailles au ratio cible ; sans identifiant, il prend les images
produit cadrées (rectangle posé ou fond assumé). `--dry-run` annonce ce qui serait écrit
sans rien écrire.

`--webp-manquants` est le **rattrapage** : il ne traite que les pièces jointes dont au moins
une harmonisée n'a pas de frère `.webp` sur le disque. C'est la commande à passer après une
mise à jour sur un site où des harmonisées ont été écrites sans leur WebP (cf. 0.2.2). Elle
est idempotente — une fois passée, elle annonce « rien à rattraper » — accepte `--dry-run`,
et refuse une liste d'identifiants puisqu'elle choisit elle-même les pièces jointes :

```
wp images-legeres harmoniser --webp-manquants --dry-run
wp images-legeres harmoniser --webp-manquants
```

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

La colonne `methode_ssim` dit d'où vient le chiffre. `imagick` : la métrique d'ImageMagick 7.
`php` : le même SSIM recalculé par l'extension (`ImagesLegeres_Ssim`), à pleine résolution,
avec la définition de Wang et al. 2004 — fenêtre gaussienne 11×11 d'écart-type 1,5 sur la
luminance Rec. 709, celle de `compare -metric SSIM`. Ce repli sert sur les hébergements dont
l'ImageMagick est en version 6 (Infomaniak mutualisé, 6.9.11) : la métrique y est absente, et
la commande y mourait sans message avant la mission F4.

Le repli n'était pourtant toujours pas atteint sur l'hébergement : la normalisation qui le
précède écrivait `Imagick::ALPHACHANNEL_OFF`, une constante d'ImageMagick 7, et une constante
de classe absente lève une `Error` fatale en PHP 8. Depuis, toute constante `Imagick::` dont
la présence dépend de la version passe par `ImagesLegeres_Compat` : retrait de l'alpha par
`ALPHACHANNEL_OFF`, sinon `ALPHACHANNEL_DEACTIVATE` (ImageMagick 6), sinon
`ALPHACHANNEL_REMOVE`, sinon aplatissement sur fond blanc. Deux constantes de `wp-config.php`
permettent d'exercer ces chemins là où ImageMagick 7 est installé :
`IMAGES_LEGERES_SIMULER_IMAGEMAGICK_6` masque les constantes propres à ImageMagick 7, et
`IMAGES_LEGERES_MASQUER_CONSTANTES_IMAGICK` (liste séparée par des virgules) en masque
d'autres. Aucune des deux n'est définie en production. Étalonné à 0,0009 près du témoin de
référence sur huit paires, photos de bougies réelles comprises. La commande rend toujours un
code de sortie non nul dès qu'une pièce jointe n'a pas pu être mesurée, avec son motif.

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

**Ce qu'est une « image produit »** (définition unique, `ImagesLegeres_Moteur::est_image_produit()`,
partagée par la qualité d'encodage, le recadrage, les flux, la file des lots et ces
commandes) : une pièce jointe rattachée à un produit ou à une variation (`post_parent`), **ou**
affichée par l'un d'eux — image principale (`_thumbnail_id`) ou galerie
(`_product_image_gallery`). Une image téléversée depuis la médiathèque puis posée sur une
fiche garde `post_parent = 0` : c'est le cas le plus courant sur un site réel, et elle compte.
L'inverse — rattachée mais affichée nulle part — compte aussi.

`crop pending` liste les images produit hors ratio cible sans rectangle ni marque « fond
assumé » ; `crop import` pose un lot de rectangles depuis un fichier JSON (une entrée invalide
est refusée seule, rien n'est écrit pour elle, code de sortie non nul si au moins un échec).
`alt missing` liste les pièces jointes affichées sans texte alternatif — l'extension ne
l'écrit jamais, poser le texte se fait avec `wp post meta update <id> _wp_attachment_image_alt
"<texte>"`. `flux check` liste les images produit dont le carré de flux ferait moins de
500 px de côté.

## Retour en arrière

Trois niveaux, dans l'écran d'administration et dans l'écran « Extensions » :

- **Désactiver** : la réécriture s'arrête, le site revient à ses images d'origine — WebP
  **et** sous-tailles harmonisées — en moins d'une minute. Rien n'est supprimé sur le disque
  ni en base.
- **Tout rendre** (bouton dans l'encart État) : restaure les originaux retaillés, supprime
  tous les fichiers générés — frères `.webp` de l'original **et de chaque sous-taille**,
  sous-tailles harmonisées, images de flux, dossier `images-legeres/` — puis désinstalle. `wp images-legeres orphelins`
  doit alors rendre une liste vide.
- **Désinstaller** (depuis l'écran « Extensions ») : supprime la table, les options et les
  métas de l'extension ; laisse les fichiers.

Aucun « drop-in » (`object-cache.php`, `advanced-cache.php`) n'est jamais posé.

## Développement

Environnement de test jetable (WordPress + WooCommerce + MariaDB, local, non publié) :

```
tests/env/up.sh
tests/env/down.sh
```

Avant les tests qui utilisent des images témoins (`test-moteur.sh` et les suivants), générer
les témoins une fois par environnement — ils ne sont pas commités :

```
docker compose -f tests/env/compose.yml exec -T wordpress \
  php /var/www/html/wp-content/plugins/images-legeres/tests/fixtures/generer-temoins.php
```

Tests : un script par sujet dans `tests/` — `test-activation.sh`, `test-moteur.sh`,
`test-recadrage.sh`, `test-cli-interfaces.sh`, `test-flux.sh`, `test-lots.sh`,
`test-auto.sh` (traitement automatique des nouvelles images, par WP-Cron réel), et
`test-securite.sh` (protection d'accès des dossiers, suppression d'une pièce jointe,
bornage du détournement Meta, traversée de chemin, nettoyage complet).
`test-activation.sh` tourne **dans** le conteneur `cli`
(`docker compose -f tests/env/compose.yml run --rm cli bash /var/www/html/wp-content/plugins/images-legeres/tests/test-activation.sh`) ;
tous les autres tournent depuis l'hôte, le conteneur `cli` n'ayant pas de délégué WebP.

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
