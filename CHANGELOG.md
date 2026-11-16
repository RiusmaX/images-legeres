# Journal des changements

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
