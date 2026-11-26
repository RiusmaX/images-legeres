#!/usr/bin/env bash
# Test de l'harmonisation produit par recadrage (mission 15, revue F5) : méta
# `_images_legeres_crop`, primitives de rendu, repli « carré centré », survie du rectangle à
# une retaille.
#
# Depuis F5, l'harmonisation n'écrit plus sous le nom de fichier natif : elle produit des
# fichiers `<base>-il-harmonise-<L>x<H>.<ext>` à côté, par `wp images-legeres harmoniser`.
# Les sous-tailles natives restent celles de WordPress et de WooCommerce — c'est ce que ces
# cas vérifient aussi.
#
# Le conteneur `cli` (image `wordpress:cli-php8.4`) n'a aucun délégué Imagick utilisable —
# `Imagick::queryFormats()` y rend un tableau vide pour JPEG comme pour PNG (confirmé le
# 2026-11-15, au-delà du seul manque de WebP relevé par la mission 13). Comme
# `test-moteur.sh`, ce script tourne donc depuis l'HÔTE : il récupère le binaire `wp` (phar)
# depuis l'image `cli` et l'exécute avec le PHP du conteneur `wordpress` (délégués complets),
# pour avoir à la fois wp-cli et un Imagick qui fonctionne. Ne modifie pas tests/env/.
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides) : la suite pose
# et efface des réglages globaux (ratio cible, harmonisation) et crée ses propres pièces
# jointes. Ne pas enchaîner les suites sur la même instance.
#
# Usage : bash tests/test-recadrage.sh   (tests/env/up.sh doit déjà avoir tourné)
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"

TMP_PHAR=$(mktemp)
docker compose -f compose.yml create cli >/dev/null 2>&1
CLI_CID=$(docker compose -f compose.yml ps -a -q cli)
docker cp "$CLI_CID":/usr/local/bin/wp "$TMP_PHAR" >/dev/null
docker compose -f compose.yml rm -f cli >/dev/null 2>&1
WP_CID=$(docker compose -f compose.yml ps -q wordpress)
docker cp "$TMP_PHAR" "$WP_CID":/usr/local/bin/wp-cli.phar >/dev/null
rm -f "$TMP_PHAR"

wp() {
  docker compose -f compose.yml exec -T wordpress php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html "$@"
}

ECHECS=0
RUN_ID=$$-$RANDOM

verifier() {
  local description="$1"
  local resultat="$2"
  if [[ "$resultat" -eq 0 ]]; then
    echo "OK   - $description"
  else
    echo "FAIL - $description"
    ECHECS=$((ECHECS + 1))
  fi
}

echo "--- préparation : réglages par défaut, harmonisation active ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts());' >/dev/null
wp eval 'ImagesLegeres_Reglages::enregistrer(array("harmonisation_produit_active" => true));' >/dev/null

echo "--- produit témoin ---"
ID_PRODUIT_WC=$(wp eval "echo wp_insert_post(array('post_type'=>'product','post_title'=>'Produit témoin','post_status'=>'publish'));" | tr -d '\r')
verifier "produit témoin créé" $([[ "$ID_PRODUIT_WC" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

# Crée une image JPEG de $largeur x $hauteur : fond bleu, carré rouge plein aux coordonnées
# pixel ($rx,$ry,$rw,$rh). Écrite dans le conteneur, à $chemin (chemin absolu).
creer_image_marqueurs() {
  local chemin="$1" largeur="$2" hauteur="$3" rx="$4" ry="$5" rw="$6" rh="$7"
  wp eval "
\$i = new Imagick();
\$i->newImage( $largeur, $hauteur, new ImagickPixel( 'blue' ) );
\$i->setImageFormat( 'jpeg' );
\$d = new ImagickDraw();
\$d->setFillColor( new ImagickPixel( 'red' ) );
\$d->rectangle( $rx, $ry, $rx + $rw - 1, $ry + $rh - 1 );
\$i->drawImage( \$d );
\$i->writeImage( '$chemin' );
echo 'ok';
" >/dev/null
}

importer_et_rattacher() {
  local chemin="$1"
  local id
  id=$(wp eval "
\$uploads = wp_upload_dir();
\$dest = trailingslashit( \$uploads['path'] ) . basename( '$chemin' );
copy( '$chemin', \$dest );
\$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => basename( '$chemin' ), 'post_status' => 'inherit', 'post_parent' => $ID_PRODUIT_WC ), \$dest );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( \$id, wp_generate_attachment_metadata( \$id, \$dest ) );
echo \$id;
" | tr -d '\r')
  echo "$id"
}

chemin_attachment() {
  wp eval "echo get_attached_file( $1 );" | tr -d '\r'
}

couleur_moyenne_harmonisee() {
  # Même échantillonnage, sur la sous-taille HARMONISÉE produite par l'extension.
  local id="$1" taille="$2"
  wp eval "
\$lignes = ImagesLegeres_Harmonisees::par_attachment( $id );
\$chemin = '';
foreach ( \$lignes as \$l ) { if ( \$l['taille'] === '$taille' ) { \$u = wp_upload_dir(); \$chemin = trailingslashit( \$u['basedir'] ) . \$l['chemin_harmonise']; } }
if ( '' === \$chemin || ! file_exists( \$chemin ) ) { echo 'absent'; exit; }
\$img = new Imagick( \$chemin );
\$l = \$img->getImageWidth();
\$h = \$img->getImageHeight();
\$r = \$g = \$b = 0; \$n = 0;
for ( \$i = 0; \$i <= 4; \$i++ ) {
  for ( \$j = 0; \$j <= 4; \$j++ ) {
    \$x = (int) round( ( 0.35 + \$i * 0.075 ) * \$l );
    \$y = (int) round( ( 0.35 + \$j * 0.075 ) * \$h );
    \$c = \$img->getImagePixelColor( \$x, \$y )->getColor();
    \$r += \$c['r']; \$g += \$c['g']; \$b += \$c['b']; \$n++;
  }
}
echo round( \$r / \$n ) . ',' . round( \$g / \$n ) . ',' . round( \$b / \$n );
" | tr -d '\r'
}

est_rouge() {
  # "r,g,b" -> vrai si dominante rouge nette (tolère l'anticrénelage du dessin + lanczos).
  local rgb="$1"
  local r g b
  IFS=',' read -r r g b <<<"$rgb"
  [[ "$r" -gt 180 && "$b" -lt 80 ]]
}

est_bleu() {
  local rgb="$1"
  local r g b
  IFS=',' read -r r g b <<<"$rgb"
  [[ "$b" -gt 180 && "$r" -lt 80 ]]
}

echo "--- unité : calculer_boite_recadrage, les 4 ratios d'origine, ±2 px ---"
# (largeur, hauteur, x_rel, y_rel, w_rel, h_rel, x_px attendu, y_px attendu, côté attendu)
CAS_RATIOS=(
  "1000 1000 0.2 0.2 0.6 0.6 200 200 600"
  "900 1200 0.1 0.15 0.8 0.6 90 180 720"
  "960 1200 0.05 0.2 0.9 0.72 48 240 864"
  "800 1200 0.0 0.1 1.0 0.667 0 120 800"
)
for cas in "${CAS_RATIOS[@]}"; do
  read -r L H XR YR WR HR XPX YPX COTE <<<"$cas"
  SORTIE=$(wp eval "
\$b = ImagesLegeres_Rendu::calculer_boite_recadrage( array( 'x' => $XR, 'y' => $YR, 'w' => $WR, 'h' => $HR ), $L, $H );
echo \$b['x'] . ',' . \$b['y'] . ',' . \$b['largeur'] . ',' . \$b['hauteur'];
" | tr -d '\r')
  IFS=',' read -r BX BY BL BH <<<"$SORTIE"
  ECART_X=$(( BX > XPX ? BX - XPX : XPX - BX ))
  ECART_Y=$(( BY > YPX ? BY - YPX : YPX - BY ))
  ECART_L=$(( BL > COTE ? BL - COTE : COTE - BL ))
  if [[ "$ECART_X" -le 2 && "$ECART_Y" -le 2 && "$ECART_L" -le 2 ]]; then
    verifier "ratio ${L}x${H} : boîte de recadrage conforme à ±2 px (x=$BX y=$BY côté=$BL)" 0
  else
    verifier "ratio ${L}x${H} : boîte de recadrage conforme à ±2 px (attendu x=$XPX y=$YPX côté=$COTE ; obtenu x=$BX y=$BY largeur=$BL hauteur=$BH)" 1
  fi
done

echo "--- bout en bout : rectangle posé, régénération, sous-taille carrée conforme ---"
IMG1=/tmp/marqueurs-1-${RUN_ID}.jpg
creer_image_marqueurs "$IMG1" 1000 1000 200 200 600 600
ID1=$(importer_et_rattacher "$IMG1")
verifier "import image marqueurs" $([[ "$ID1" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

RESULTAT_DEF=$(wp eval "
\$r = ImagesLegeres_Recadrage::definir( $ID1, 200, 200, 600, 600 );
echo is_wp_error( \$r ) ? 'erreur:' . \$r->get_error_message() : 'ok';
" | tr -d '\r')
verifier "rectangle posé (200,200,600,600 sur 1000x1000)" $([[ "$RESULTAT_DEF" == "ok" ]] && echo 0 || echo 1)

wp media regenerate "$ID1" --yes >/dev/null 2>&1

# Empreintes des natifs AVANT harmonisation : l'extension ne doit en toucher aucun.
EMPREINTES_AVANT=$(wp eval "
\$m = wp_get_attachment_metadata( $ID1 );
\$d = trailingslashit( dirname( get_attached_file( $ID1 ) ) );
\$s = array();
foreach ( \$m['sizes'] as \$nom => \$t ) { \$s[] = \$nom . ':' . md5_file( \$d . \$t['file'] ); }
sort( \$s ); echo implode( ' ', \$s );" | tr -d '\r')

wp images-legeres harmoniser "$ID1" >/dev/null 2>&1

EMPREINTES_APRES=$(wp eval "
\$m = wp_get_attachment_metadata( $ID1 );
\$d = trailingslashit( dirname( get_attached_file( $ID1 ) ) );
\$s = array();
foreach ( \$m['sizes'] as \$nom => \$t ) { \$s[] = \$nom . ':' . md5_file( \$d . \$t['file'] ); }
sort( \$s ); echo implode( ' ', \$s );" | tr -d '\r')
verifier "harmonisation : aucun fichier natif modifié (empreintes identiques)" $([[ -n "$EMPREINTES_AVANT" && "$EMPREINTES_AVANT" == "$EMPREINTES_APRES" ]] && echo 0 || echo 1)

RGB_THUMB=$(couleur_moyenne_harmonisee "$ID1" woocommerce_thumbnail)
est_rouge "$RGB_THUMB"
verifier "harmonisée woocommerce_thumbnail (300x300) conforme au rectangle : uniformément rouge ($RGB_THUMB)" $?

RGB_SINGLE=$(couleur_moyenne_harmonisee "$ID1" woocommerce_single)
est_rouge "$RGB_SINGLE"
verifier "harmonisée woocommerce_single conforme au rectangle : uniformément rouge ($RGB_SINGLE)" $?

DIM_SINGLE=$(wp eval "
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID1 ) as \$l ) { if ( 'woocommerce_single' === \$l['taille'] ) { echo \$l['largeur'] . 'x' . \$l['hauteur']; } }" | tr -d '\r')
LARGEUR_SINGLE="${DIM_SINGLE%%x*}"
HAUTEUR_SINGLE="${DIM_SINGLE##*x}"
verifier "harmonisée de la fiche produit carrée (obtenu $DIM_SINGLE)" $([[ -n "$DIM_SINGLE" && "$LARGEUR_SINGLE" == "$HAUTEUR_SINGLE" ]] && echo 0 || echo 1)

NATIF_SINGLE=$(wp eval "\$m = wp_get_attachment_metadata($ID1); echo \$m['sizes']['woocommerce_single']['width'] . 'x' . \$m['sizes']['woocommerce_single']['height'];" | tr -d '\r')
verifier "woocommerce_single natif laissé tel que WooCommerce le déclare (obtenu $NATIF_SINGLE)" $([[ "$NATIF_SINGLE" != "" ]] && echo 0 || echo 1)

echo "--- sans rectangle : carré centré, aucune bande ajoutée ---"
IMG2=/tmp/marqueurs-2-${RUN_ID}.jpg
# Photo 3:4 (900x1200). Le carré centré va de y=150 à y=1049 : moitié gauche verte, moitié
# droite bleue. Au-dessus et en dessous, du rouge, qui doit disparaître. Un fond ajouté
# garderait le rouge et poserait une couleur unie sur les côtés.
wp eval "
\$i = new Imagick();
\$i->newImage( 900, 1200, new ImagickPixel( 'red' ) );
\$i->setImageFormat( 'jpeg' );
\$d = new ImagickDraw();
\$d->setFillColor( new ImagickPixel( '#00ff00' ) );
\$d->rectangle( 0, 150, 449, 1049 );
\$d->setFillColor( new ImagickPixel( 'blue' ) );
\$d->rectangle( 450, 150, 899, 1049 );
\$i->drawImage( \$d );
\$i->writeImage( '$IMG2' );
echo 'ok';
" >/dev/null
ID2=$(importer_et_rattacher "$IMG2")
verifier "import image sans rectangle" $([[ "$ID2" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

AVANT=$(wp eval "echo ImagesLegeres_Recadrage::obtenir( $ID2 ) === null ? 'null' : 'pose';" | tr -d '\r')
verifier "aucun rectangle posé au départ" $([[ "$AVANT" == "null" ]] && echo 0 || echo 1)

# Pour chaque harmonisée de la pièce jointe : carrée, et sur ses quatre bords les couleurs
# de la photo (vert à gauche, bleu à droite, les deux en haut et en bas). Rend « ok:<n> ».
bords_de_la_photo() {
  wp eval "
\$u = wp_upload_dir(); \$n = 0; \$ko = array();
\$proche = function ( \$c, \$v, \$b ) { return \$c['r'] < 60 && abs( \$c['g'] - \$v ) < 60 && abs( \$c['b'] - \$b ) < 60; };
foreach ( ImagesLegeres_Harmonisees::par_attachment( $1 ) as \$ligne ) {
  \$img = new Imagick( trailingslashit( \$u['basedir'] ) . \$ligne['chemin_harmonise'] );
  \$l = \$img->getImageWidth(); \$h = \$img->getImageHeight(); \$n++;
  if ( \$l !== \$h ) { \$ko[] = \$ligne['taille'] . ' non carrée'; continue; }
  foreach ( array( 0, 1, (int) ( \$h / 2 ), \$h - 2, \$h - 1 ) as \$y ) {
    if ( ! \$proche( \$img->getImagePixelColor( 0, \$y )->getColor(), 255, 0 ) ) { \$ko[] = \$ligne['taille'] . ' bord gauche y=' . \$y; }
    if ( ! \$proche( \$img->getImagePixelColor( \$l - 1, \$y )->getColor(), 0, 255 ) ) { \$ko[] = \$ligne['taille'] . ' bord droit y=' . \$y; }
  }
  foreach ( array( 0, \$h - 1 ) as \$y ) {
    if ( ! \$proche( \$img->getImagePixelColor( (int) ( \$l / 4 ), \$y )->getColor(), 255, 0 ) || ! \$proche( \$img->getImagePixelColor( (int) ( 3 * \$l / 4 ), \$y )->getColor(), 0, 255 ) ) { \$ko[] = \$ligne['taille'] . ' bord horizontal y=' . \$y; }
  }
}
echo ( \$n && ! \$ko ) ? 'ok:' . \$n : 'ko:' . implode( ', ', \$ko );
" | tr -d '\r'
}

wp media regenerate "$ID2" --yes >/dev/null 2>&1

# Un carré « grand » d'avant la 0.2.4 (côté 1200, avec fond) : il doit disparaître.
PERIMEE=$(wp eval "
\$u = wp_upload_dir();
\$o = get_attached_file( $ID2 );
\$f = ImagesLegeres_Harmonisation::chemin_harmonise( \$o, 1200, 1200 );
copy( \$o, \$f ); copy( \$o, \$f . '.webp' );
ImagesLegeres_Harmonisees::enregistrer( array( 'attachment_id' => $ID2, 'taille' => 'images_legeres_grande', 'chemin_natif' => ImagesLegeres_Moteur::chemin_relatif( \$o ), 'chemin_harmonise' => ImagesLegeres_Moteur::chemin_relatif( \$f ), 'largeur' => 1200, 'hauteur' => 1200 ) );
echo \$f;
" | tr -d '\r')

wp images-legeres harmoniser "$ID2" >/dev/null 2>&1

BORDS=$(bords_de_la_photo "$ID2")
verifier "sans rectangle : harmonisées carrées, bords = pixels de la photo, aucune bande ($BORDS)" $([[ "$BORDS" == ok:* ]] && echo 0 || echo 1)

RESTE_PERIMEE=$(wp eval "
\$n = 0; foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID2 ) as \$l ) { if ( 1200 === (int) \$l['largeur'] ) { \$n++; } }
echo \$n . ':' . (int) file_exists( '$PERIMEE' ) . ':' . (int) file_exists( '$PERIMEE.webp' );" | tr -d '\r')
verifier "ancien carré « grand » avec fond : rang et fichiers retirés (obtenu $RESTE_PERIMEE)" $([[ "$RESTE_PERIMEE" == "0:0:0" ]] && echo 0 || echo 1)

GRANDE=$(wp eval "
\$t = ImagesLegeres_Harmonisation::taille_grande( get_attached_file( $ID2 ), null, array() ); echo \$t[0] . 'x' . \$t[1];" | tr -d '\r')
verifier "sans rectangle : le plus grand carré sans agrandir fait le petit côté (obtenu $GRANDE)" $([[ "$GRANDE" == "900x900" ]] && echo 0 || echo 1)

APRES=$(wp eval "echo ImagesLegeres_Recadrage::obtenir( $ID2 ) === null ? 'null' : 'pose';" | tr -d '\r')
verifier "toujours aucun rectangle : le carré centré n'en pose pas lui-même" $([[ "$APRES" == "null" ]] && echo 0 || echo 1)

PAR_DEFAUT=$(wp eval "echo in_array( $ID2, ImagesLegeres_Harmonisation::ids_a_harmoniser(), true ) ? '1' : '0';" | tr -d '\r')
verifier "« harmoniser » sans identifiant reprend une photo déjà harmonisée sans rectangle" $([[ "$PAR_DEFAUT" == "1" ]] && echo 0 || echo 1)

echo "--- fond assumé : marqué, pas de rectangle, carré centré aussi ---"
wp eval "echo ImagesLegeres_Recadrage::marquer_fond_assume( $ID2, 'aucun carré ne contient le produit entier' ) ? 'ok' : 'echec';" >/dev/null
EST_FOND_ASSUME=$(wp eval "echo ImagesLegeres_Recadrage::fond_assume( $ID2 ) ? '1' : '0';" | tr -d '\r')
verifier "fond_assume() rend vrai après marquage" $([[ "$EST_FOND_ASSUME" == "1" ]] && echo 0 || echo 1)
TOUJOURS_SANS_RECTANGLE=$(wp eval "echo ImagesLegeres_Recadrage::obtenir( $ID2 ) === null ? 'null' : 'pose';" | tr -d '\r')
verifier "fond_assume n'écrit pas de rectangle (obtenir() reste null)" $([[ "$TOUJOURS_SANS_RECTANGLE" == "null" ]] && echo 0 || echo 1)
wp images-legeres harmoniser "$ID2" >/dev/null 2>&1
BORDS_ASSUME=$(bords_de_la_photo "$ID2")
verifier "fond assumé : carré centré, aucune bande ($BORDS_ASSUME)" $([[ "$BORDS_ASSUME" == ok:* ]] && echo 0 || echo 1)

FLUX=$(wp eval "
\$r = ImagesLegeres_Flux::generer( $ID2 );
\$img = new Imagick( ImagesLegeres_Flux::dossier_absolu() . \$r['chemin'] );
\$c = \$img->getImagePixelColor( (int) ( \$img->getImageWidth() / 4 ), 2 )->getColor();
echo \$r['dimension'] . ':' . ( \$c['r'] > 180 && \$c['g'] < 80 ? 'entiere' : 'coupee' );" | tr -d '\r')
verifier "flux inchangés : photo entière sur fond, côté 1200 (obtenu $FLUX)" $([[ "$FLUX" == "1200:entiere" ]] && echo 0 || echo 1)

echo "--- rectangle refusé : hors image, mauvais ratio, trop petit ---"
HORS_IMAGE=$(wp eval "echo is_wp_error( ImagesLegeres_Recadrage::definir( $ID1, 700, 700, 600, 600 ) ) ? 'refuse' : 'accepte';" | tr -d '\r')
verifier "rectangle qui dépasse l'image : refusé" $([[ "$HORS_IMAGE" == "refuse" ]] && echo 0 || echo 1)

MAUVAIS_RATIO=$(wp eval "echo is_wp_error( ImagesLegeres_Recadrage::definir( $ID1, 100, 100, 600, 300 ) ) ? 'refuse' : 'accepte';" | tr -d '\r')
verifier "rectangle 2:1 sur ratio cible 1:1 : refusé" $([[ "$MAUVAIS_RATIO" == "refuse" ]] && echo 0 || echo 1)

# Rectangle de 400x400 sur une référence 1000x1000 déclarée : 400 px de côté, sous le
# plancher de 500 (ADR 0005 point 3) — doit être refusé pour cette raison précise.
TROP_PETIT_MOTIF=$(wp eval "
\$r = ImagesLegeres_Recadrage::definir( $ID1, 0, 0, 400, 400, 1000, 1000 );
echo is_wp_error( \$r ) ? \$r->get_error_code() : 'accepte';
" | tr -d '\r')
verifier "rectangle de 400 px de côté (< 500) : refusé pour taille insuffisante (code $TROP_PETIT_MOTIF)" $([[ "$TROP_PETIT_MOTIF" == "images_legeres_trop_petit" ]] && echo 0 || echo 1)

RECTANGLE_APRES_REFUS=$(wp eval "
\$r = ImagesLegeres_Recadrage::obtenir( $ID1 );
echo \$r['x'] . ',' . \$r['y'] . ',' . \$r['w'] . ',' . \$r['h'];
" | tr -d '\r')
verifier "les refus n'ont rien écrit : le rectangle valide posé plus haut est intact ($RECTANGLE_APRES_REFUS)" $([[ "$RECTANGLE_APRES_REFUS" == "0.2,0.2,0.6,0.6" ]] && echo 0 || echo 1)

# Le plancher de 500 px porte sur le PLUS PETIT côté (min), pas sur le plus grand (max) :
# un rectangle 480x720 au ratio 2:3 ferait 480 px de large, sous le plancher, alors que son
# plus grand côté tient largement. Les seuls cas sous 500 px testés jusqu'ici étaient carrés
# (400x400), où min et max sont égaux : remplacer min par max dans
# ImagesLegeres_Recadrage::valider() passait toute la suite (mutation vérifiée par le
# contrôle qa du 2026-11-15). Ces deux cas non carrés ferment le trou.
echo "--- plancher de 500 px sur un ratio non carré (2:3) ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(array("ratio_cible" => "2:3"));' >/dev/null

TROP_PETIT_NON_CARRE=$(wp eval "
\$r = ImagesLegeres_Recadrage::definir( $ID1, 0, 0, 480, 720, 1000, 1500 );
echo is_wp_error( \$r ) ? \$r->get_error_code() : 'accepte';
" | tr -d '\r')
verifier "rectangle 480x720 (2:3, petit côté 480 < 500, grand côté 720 >= 500) : refusé pour taille insuffisante (code $TROP_PETIT_NON_CARRE)" $([[ "$TROP_PETIT_NON_CARRE" == "images_legeres_trop_petit" ]] && echo 0 || echo 1)

ASSEZ_GRAND_NON_CARRE=$(wp eval "
\$r = ImagesLegeres_Recadrage::definir( $ID1, 0, 0, 520, 780, 1000, 1500 );
echo is_wp_error( \$r ) ? \$r->get_error_code() : 'accepte';
" | tr -d '\r')
verifier "rectangle 520x780 (2:3, petit côté 520 >= 500) : accepté (obtenu $ASSEZ_GRAND_NON_CARRE)" $([[ "$ASSEZ_GRAND_NON_CARRE" == "accepte" ]] && echo 0 || echo 1)

# Remise du ratio cible par défaut : la suite continue sur du 1:1.
wp eval 'ImagesLegeres_Reglages::enregistrer(array("ratio_cible" => "1:1"));' >/dev/null
wp eval "ImagesLegeres_Recadrage::definir( $ID1, 200, 200, 600, 600, 1000, 1000 );" >/dev/null

echo "--- effacer() retire le rectangle ---"
wp eval "ImagesLegeres_Recadrage::effacer( $ID1 );" >/dev/null
EFFACE=$(wp eval "echo ImagesLegeres_Recadrage::obtenir( $ID1 ) === null ? 'null' : 'pose';" | tr -d '\r')
verifier "effacer() retire bien le rectangle" $([[ "$EFFACE" == "null" ]] && echo 0 || echo 1)

echo "--- aucune taille déclarée n'est modifiée, harmonisation active ou non (F5) ---"
# Le filtre `woocommerce_get_image_size_single` est retiré : une taille déclarée modifiée
# fait fabriquer à WooCommerce une sous-taille NATIVE carrée, servie à tous les visiteurs
# sans passer par la réécriture d'adresse — le mode aperçu ne protégeait plus rien.
AVEC_HARMONISATION=$(wp eval "
\$t = apply_filters( 'woocommerce_get_image_size_single', array( 'width' => 1024, 'height' => 0, 'crop' => 0 ) );
echo \$t['width'] . 'x' . \$t['height'] . ':' . ( \$t['crop'] ? '1' : '0' );
" | tr -d '\r')
verifier "harmonisation active : woocommerce_single NON filtré (obtenu $AVEC_HARMONISATION)" $([[ "$AVEC_HARMONISATION" == "1024x0:0" ]] && echo 0 || echo 1)

AUCUN_EDITEUR=$(wp eval "
\$e = apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) );
echo implode( ',', \$e );
" | tr -d '\r')
verifier "aucun éditeur d'image branché par l'extension (obtenu $AUCUN_EDITEUR)" $([[ "$AUCUN_EDITEUR" != *"ImagesLegeres"* ]] && echo 0 || echo 1)

echo "--- un rectangle survit à la retaille de l'original (2560 -> 1920 px) ---"
IMG3=/tmp/marqueurs-3-${RUN_ID}.jpg
creer_image_marqueurs "$IMG3" 2560 2560 512 512 1536 1536
ID3=$(importer_et_rattacher "$IMG3")
verifier "import image 2560 px" $([[ "$ID3" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

wp eval "ImagesLegeres_Recadrage::definir( $ID3, 512, 512, 1536, 1536 );" >/dev/null
wp media regenerate "$ID3" --yes >/dev/null 2>&1
wp images-legeres harmoniser "$ID3" >/dev/null 2>&1
RGB_AVANT_RETAILLE=$(couleur_moyenne_harmonisee "$ID3" woocommerce_thumbnail)
est_rouge "$RGB_AVANT_RETAILLE"
verifier "sous-taille conforme avant retaille ($RGB_AVANT_RETAILLE)" $?

LARGEUR_AVANT=$(wp eval "echo (new Imagick('$(chemin_attachment "$ID3")'))->getImageWidth();" | tr -d '\r')
verifier "original à 2560 px avant retaille" $([[ "$LARGEUR_AVANT" == "2560" ]] && echo 0 || echo 1)

wp eval 'ImagesLegeres_Reglages::enregistrer(array("retaille_originaux_active" => true));' >/dev/null
wp images-legeres convert "$ID3" >/dev/null 2>&1
wp eval 'ImagesLegeres_Reglages::enregistrer(array("retaille_originaux_active" => false));' >/dev/null

LARGEUR_APRES=$(wp eval "echo (new Imagick('$(chemin_attachment "$ID3")'))->getImageWidth();" | tr -d '\r')
verifier "original retaillé à 1920 px au plus (obtenu $LARGEUR_APRES)" $([[ "$LARGEUR_APRES" =~ ^[0-9]+$ && "$LARGEUR_APRES" -le 1920 ]] && echo 0 || echo 1)

RECTANGLE_INCHANGE=$(wp eval "
\$r = ImagesLegeres_Recadrage::obtenir( $ID3 );
echo \$r['x'] . ',' . \$r['y'] . ',' . \$r['w'] . ',' . \$r['h'];
" | tr -d '\r')
verifier "le rectangle relatif lui-même n'a pas changé après retaille ($RECTANGLE_INCHANGE)" $([[ "$RECTANGLE_INCHANGE" == "0.2,0.2,0.6,0.6" ]] && echo 0 || echo 1)

wp images-legeres harmoniser "$ID3" >/dev/null 2>&1
RGB_APRES_RETAILLE=$(couleur_moyenne_harmonisee "$ID3" woocommerce_thumbnail)
est_rouge "$RGB_APRES_RETAILLE"
verifier "sous-taille toujours conforme après retaille 2560->1920 : la zone cadrée n'a pas bougé ($RGB_APRES_RETAILLE)" $?

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
