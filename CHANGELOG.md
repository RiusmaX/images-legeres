# Journal des changements

## 0.2.4

- **Plus de bande de couleur autour d'une photo produit.** Sans rectangle de recadrage, la
  photo harmonisée est désormais le carré centré, le plus grand que l'original contient, au
  lieu de la photo entière sur un fond ajouté. Même chose pour les photos marquées « fond
  assumé ». Un rectangle posé reste prioritaire, au rendu inchangé.
- `crop pending` liste aussi les photos marquées « fond assumé » : elles restent à recadrer.
  `crop import` accepte toujours cette marque.
- `harmoniser` sans identifiant reprend aussi les photos déjà harmonisées. **À lancer après
  la mise à jour**, puis vider les caches de pages : les carrés avec fond déjà écrits ne sont
  pas refaits tout seuls.
- Quand la taille du carré « grand » change, l'ancien est retiré (inventaire et fichiers) : il
  ne reste pas servi à l'agrandissement.
- Les images de flux Google et Meta ne changent pas.
- **Seule une image affichée par un produit est à recadrer et à harmoniser** (image
  principale ou galerie d'un produit ou d'une variation). Les fichiers seulement rattachés au
  produit, comme les pictogrammes d'ingrédients, sortent de `crop pending` et de
  l'harmonisation. La qualité d'encodage et les flux les traitent comme avant.
- **Les originaux déjà en WebP sont harmonisés** : carré écrit en WebP, sans fichier en
  double, et servi dans la page (la réécriture reconnaît désormais les adresses `.webp`).
  Auparavant ignorés (« déjà au format webp »), ils restaient dans leurs proportions
  d'origine malgré un cadrage. La conversion WebP les ignore toujours.

## 0.2.3

- **Plus aucune réécriture dans l'éditeur ni dans les aperçus.** L'éditeur visuel Divi
  recevait des adresses en `.webp`, et un enregistrement les écrivait dans le contenu. Ni
  WebP ni harmonisée sur `et_fb`, `app_window`, `et_pb_preview`, `et_pb_preview_nonce`,
  `et_tb`, l'aperçu WordPress ou de l'outil de personnalisation, l'administration, AJAX,
  REST et les requêtes JSON. Les paramètres d'édition et d'aperçu ne comptent que pour un
  utilisateur connecté qui peut modifier : un visiteur qui les ajoute à l'adresse reçoit la
  page réécrite. La page publique est réécrite comme avant. Un contenu déjà
  enregistré avec ces adresses n'est pas corrigé par la mise à jour.
- **Les nouvelles images sont traitées toutes seules.** Une image mise en file par un envoi ou
  un changement de sous-tailles restait « à faire » tant que personne ne lançait le lot. Une
  action de fond dédiée la convertit désormais en quelques minutes, hors plage horaire, avec
  le même garde-fou de charge. La plage horaire reste la règle des lots manuels.
- **Les photos posées sur un produit sont harmonisées automatiquement** (image principale ou
  galerie, produit ou variation) quand l'harmonisation produit est active.
- **Chaque sous-taille native d'une photo harmonisée reçoit son carré.** Des tailles de mêmes
  dimensions partagent un fichier carré : l'inventaire n'en gardait qu'une ligne, et la
  dernière écrasait le fichier natif des autres (vignettes de l'accueil et de la boutique
  restées rectangulaires). Il garde désormais une ligne par taille (migration automatique,
  sans perte). Quand l'original est trop petit pour une taille déclarée, il a lui aussi son
  carré. **Après la mise à jour, relancer `wp images-legeres harmoniser <ids>`** sur les
  photos déjà harmonisées, puis vider le cache.
- La prise d'une ligne de la file est atomique ; une nouvelle image qui coupe deux fois le
  traitement passe en erreur au lieu de bloquer les suivantes.
- **Un motif trop long ne bloque plus la file.** Au-delà de 500 caractères (une image
  d'une quinzaine de fichiers suffit), le résultat n'était pas écrit : l'image restait « en
  cours », était retraitée, puis abandonnée en erreur. Les motifs répétés sont regroupés, le
  texte est tronqué, et une écriture refusée est notée au journal.

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
- **« Tout rendre » ne supprime que les `.webp` dont l'extension est l'auteur prouvé.**
  `ImagesLegeres_Desinstallation::lister_freres_webp()` retenait toute ligne de
  l'inventaire, quel que soit son état. Une ligne `ignore` — « webp plus lourd que
  l'original », donc aucun fichier écrit par nous — suffisait à réclamer le `.webp` qu'un
  autre outil (EWWW, WebP Express, ShortPixel) aurait posé au même endroit avec un
  meilleur encodeur, et « Tout rendre » le détruisait. Seul un état `fait` prouve
  désormais la propriété ; la garde SEC-9 vaut aussi pour les sous-tailles.
- `tests/test-activation.sh` joue « Tout rendre » **avant** la désinstallation de
  niveau 3, et non après. Joué après, il travaillait sans inventaire — détruit par
  `uninstall.php` — donc sans preuve d'auteur : il ne supprimait plus rien et laissait
  13 fichiers que le contrôle d'orphelins reclassait en « autre outil ». La suite pose en
  plus le `.webp` d'un autre outil sur une sous-taille et vérifie qu'il survit.
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
