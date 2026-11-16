# Journal des changements

## 0.2.2

- **Les harmonisées ont enfin leur frère `.webp`.** Le JPEG harmonisé était écrit à la
  qualité `jpeg_quality` (82) et son frère `.webp` à `qualite_webp_produit` (90 par
  défaut) : un WebP de qualité 90 tiré d'un JPEG de qualité 82 pèse 4 à 5 % **plus
  lourd** que lui, donc `convertir_vers_webp` le refusait comme « webp plus lourd que
  l'original » et le supprimait. 40 harmonisées de production étaient restées sans WebP
  pour cette seule raison. Les deux encodages sortent désormais de la **même** valeur
  (`ImagesLegeres_Harmonisation::qualite()`), et le WebP est 21 à 28 % plus léger que son
  JPEG.
- **Les profils de l'original ne sont plus recopiés dans chaque harmonisée.**
  `ImagesLegeres_Compat::retirer_profils()` jette l'EXIF, l'XMP et les blocs du
  constructeur — environ 81 Ko par fichier — et **garde le profil couleur (ICC)**, pour
  que les couleurs ne dérivent pas. C'est le geste que WordPress fait déjà sur chaque
  sous-taille native. Si le retrait échoue, l'image sort telle quelle, plus lourde mais
  juste : aucun repli muet.
- **La grande image du zoom est plafonnée à 1 536 px de côté**
  (`ImagesLegeres_Harmonisation::COTE_GRANDE_MAX`). La version précédente servait LA plus
  grande harmonisée, donc le `2048x2048` de WordPress : 841 Ko pour une visionneuse qui,
  sur un téléphone, n'affiche jamais plus de 1 536 px. Le `1536x1536` de la même photo
  pèse 577 Ko, et 417 Ko une fois les profils retirés. Si la pièce jointe n'a aucune
  harmonisée sous le plafond, la plus petite au-dessus est gardée : mieux vaut une grande
  trop grande que le rectangle natif, qui est un autre cadrage que la photo affichée.
- **Nouveau rattrapage `wp images-legeres harmoniser --webp-manquants`** : ne traite que
  les pièces jointes dont au moins une harmonisée n'a pas de frère `.webp` sur le disque.
  Idempotent, compatible `--dry-run`, et refuse une liste d'identifiants puisqu'il choisit
  lui-même.
- L'image de test `cli` reçoit `imagemagick-webp`. Sans ce codec,
  `setImageFormat('webp')` lève « Unable to set image format » et tout le chemin WebP de
  l'extension ne s'exerçait que sur son chemin d'erreur.

## 0.2.1

- **Galerie produit : le zoom et la visionneuse montrent la photo affichée.** Quand une
  balise de galerie est harmonisée, `data-src` (jQuery Zoom), `data-large_image`,
  `data-large_image_width` / `data-large_image_height` (PhotoSwipe) et le `href` du lien
  parent suivent la plus grande harmonisée de la pièce jointe, au lieu de rester sur
  l'original rectangulaire. Jusqu'ici la fiche affichait un carré et agrandissait un
  rectangle : deux cadrages différents, et un zoom qui ne retombait pas sur le point
  touché (constat de production du 2026-11-16, fiche Vanille — carré 1024x1024 affiché,
  zoom sur 1440x1920).
- Les dimensions déclarées au zoom sont celles du fichier réellement servi, mesurées sur
  le disque, et non la taille demandée à la génération.
- Une balise sans harmonisée n'est pas touchée : jamais de carré affiché avec un
  rectangle au zoom, ni l'inverse.
- Le `href` du lien parent n'est réécrit que s'il portait bien la grande image de cette
  balise ; un lien quelconque autour d'une image produit reste intact.
- **Nouvelle taille `images_legeres_grande`**, produite par `wp images-legeres harmoniser`
  quand aucune taille déclarée au ratio cible n'est assez large pour l'agrandissement :
  le plus grand carré que l'original donne sans agrandir un seul pixel. Elle remplace
  l'original lui-même (son `chemin_natif` est celui de l'original).
- Mêmes gardes que le reste de la réécriture : mode aperçu respecté (un visiteur anonyme
  reçoit les adresses d'origine), chemin revalidé sous `uploads` avant d'être servi,
  aucune adresse vers un fichier absent.

## 0.2.0

Harmonisation produit sous fichiers propres à l'extension et inventaire dédié, mode aperçu
étendu à l'harmonisation, revalidation du chemin servi (SEC-F5-1), migrations de réglages
par registre nommé.
