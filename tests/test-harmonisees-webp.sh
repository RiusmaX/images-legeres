#!/usr/bin/env bash
# F9 : toute harmonisée a son frère .webp, et elle ne pèse pas plus que nécessaire.
#
# Constat de production du 2026-11-16 : sur les harmonisées de monkobougie.fr, 40 lignes
# d'inventaire portaient le motif « webp plus lourd que l'original » et n'avaient donc
# AUCUN frère .webp sur le disque. Cause mesurée : l'harmonisée JPEG est écrite à la
# qualité 82 (`jpeg_quality`) tandis que son frère .webp sortait de `qualite_webp_produit`,
# dont le défaut est 90. Un WebP de qualité 90 tiré d'un JPEG de qualité 82 est 4 à 5 %
# PLUS LOURD que lui ; à 82 des deux côtés il est 21 à 28 % plus léger.
# Second défaut : l'harmonisée gardait tous les profils de l'original (81 501 octets sur
# une photo Pixel), au point qu'un carré de 50 px pesait 88 802 octets.
#
# Tourne depuis l'HÔTE (wp-cli exécuté avec le PHP du conteneur `wordpress`).
# EXIGE UN WORDPRESS JETABLE (tests/env/up.sh).
# Usage : IL_HTTP_PORT=8099 bash tests/test-harmonisees-webp.sh
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
  local description="$1" resultat="$2"
  if [[ "$resultat" -eq 0 ]]; then
    echo "OK   - $description"
  else
    echo "FAIL - $description"
    ECHECS=$((ECHECS + 1))
  fi
}

echo "--- préparation ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts());' >/dev/null
wp eval 'ImagesLegeres_Reglages::enregistrer(array("harmonisation_produit_active" => true, "mode_apercu_actif" => true));' >/dev/null

# La qualité WebP « produit » reste à son défaut de 90 : c'est précisément la valeur qui
# faisait refuser les frères .webp. Si la correction tient, elle n'a plus d'effet sur les
# harmonisées, qui suivent la qualité de leur propre JPEG.
QWP=$(wp eval 'echo ImagesLegeres_Reglages::valeur("qualite_webp_produit");' | tr -d '\r')
verifier "qualité WebP produit laissée au défaut piégeux (obtenu $QWP)" $([[ "$QWP" == "90" ]] && echo 0 || echo 1)

echo "--- photo témoin bruitée, cadrée, et ses harmonisées ---"
IDS=$(wp eval "
\$produit = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Bougie F9 ${RUN_ID}', 'post_status' => 'publish' ) );
\$i = new Imagick();
\$i->newPseudoImage( 1800, 2400, 'plasma:fractal' );
\$i->addNoiseImage( Imagick::NOISE_GAUSSIAN );
// Profils volumineux de l'original, comme l'EXIF d'une photo de téléphone : c'est ce que
// l'harmonisée recopiait, et ce que la correction doit jeter.
\$i->setImageProfile( 'exif', str_repeat( 'E', 24000 ) );
\$i->setImageProfile( 'xmp', str_repeat( 'X', 2000 ) );
\$i->setImageFormat( 'jpeg' );
\$i->setImageCompressionQuality( 92 );
\$u = wp_upload_dir();
\$dest = trailingslashit( \$u['path'] ) . 'bougie-f9-${RUN_ID}.jpg';
\$i->writeImage( \$dest );
\$i->clear();
\$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'bougie f9', 'post_status' => 'inherit', 'post_parent' => \$produit ), \$dest );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( \$id, wp_generate_attachment_metadata( \$id, \$dest ) );
set_post_thumbnail( \$produit, \$id );
echo \$produit . ' ' . \$id;
" | tr -d '\r')
read -r ID_PRODUIT ID_IMAGE <<<"$IDS"
verifier "produit et image créés (produit $ID_PRODUIT, image $ID_IMAGE)" $([[ "$ID_IMAGE" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

wp eval "ImagesLegeres_Recadrage::definir( $ID_IMAGE, 0, 300, 1800, 1800 );" >/dev/null
wp images-legeres harmoniser "$ID_IMAGE" >/dev/null 2>&1

# Tous les fichiers harmonisés inventoriés (une ligne par fichier, même partagé entre
# plusieurs tailles), avec leur poids et la présence de leur frère.
etat_harmonisees() {
  wp eval "
\$u = wp_upload_dir(); \$b = trailingslashit( \$u['basedir'] );
foreach ( array_column( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ), null, 'chemin_harmonise' ) as \$l ) {
  \$f = \$b . \$l['chemin_harmonise'];
  printf( \"%s|%d|%d|%s|%d\n\", \$l['taille'], \$l['largeur'], file_exists( \$f ) ? filesize( \$f ) : -1,
    file_exists( \$f . '.webp' ) ? 'webp' : 'SANS', file_exists( \$f . '.webp' ) ? filesize( \$f . '.webp' ) : -1 );
}" | tr -d '\r'
}

echo "--- cas 1 : toute harmonisée a son frère .webp, et il est plus léger ---"
ETAT=$(etat_harmonisees)
echo "$ETAT" | sed 's/^/        /'
NB=$(echo "$ETAT" | grep -c '|' || true)
SANS=$(echo "$ETAT" | grep -c 'SANS' || true)
verifier "au moins une harmonisée produite (obtenu $NB)" $([[ "$NB" -gt 0 ]] && echo 0 || echo 1)
verifier "aucune harmonisée sans frère .webp (obtenu $SANS sans)" $([[ "$SANS" -eq 0 ]] && echo 0 || echo 1)

PLUS_LOURDS=$(echo "$ETAT" | awk -F'|' '$5 >= $3 {n++} END {print n+0}')
verifier "chaque frère .webp est plus léger que son harmonisée (obtenu $PLUS_LOURDS plus lourd(s))" \
  $([[ "$PLUS_LOURDS" -eq 0 ]] && echo 0 || echo 1)

echo "--- cas 2 : les profils de l'original ne sont pas recopiés ---"
PROFILS=$(wp eval "
\$u = wp_upload_dir(); \$b = trailingslashit( \$u['basedir'] );
\$lignes = ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE );
usort( \$lignes, function( \$a, \$b2 ) { return (int) \$a['largeur'] - (int) \$b2['largeur']; } );
\$f = \$b . \$lignes[0]['chemin_harmonise'];
\$i = new Imagick( \$f );
\$p = \$i->getImageProfiles( '*', false );
printf( '%s %d %d', implode( ',', \$p ) ?: 'aucun', \$lignes[0]['largeur'], filesize( \$f ) );" | tr -d '\r')
read -r LISTE_PROFILS PETIT_COTE PETIT_POIDS <<<"$PROFILS"
verifier "la plus petite harmonisée (${PETIT_COTE}px) ne porte ni exif ni xmp (profils : $LISTE_PROFILS)" \
  $(echo "$LISTE_PROFILS" | grep -qE 'exif|xmp' && echo 1 || echo 0)
# 26 000 octets de profils avaient été posés sur l'original : un petit carré qui les
# traînerait ne pourrait pas tenir sous ce seuil.
verifier "la plus petite harmonisée pèse moins de 20 000 octets (obtenu $PETIT_POIDS)" \
  $([[ "$PETIT_POIDS" -lt 20000 ]] && echo 0 || echo 1)

echo "--- cas 3 : harmonisée déjà pourvue, non retouchée par le rattrapage ---"
EMPREINTES_AVANT=$(wp eval "
\$u = wp_upload_dir(); \$b = trailingslashit( \$u['basedir'] );
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) as \$l ) {
  \$f = \$b . \$l['chemin_harmonise'];
  echo \$l['taille'] . ':' . ( file_exists( \$f ) ? md5_file( \$f ) : 'x' ) . ':' . ( file_exists( \$f . '.webp' ) ? md5_file( \$f . '.webp' ) : 'x' ) . \"\n\";
}" | tr -d '\r' | sort)

SORTIE_RIEN=$(wp images-legeres harmoniser --webp-manquants 2>&1 | tr -d '\r')
echo "$SORTIE_RIEN" | sed 's/^/        /'
verifier "le rattrapage annonce n'avoir rien à faire" \
  $(echo "$SORTIE_RIEN" | grep -q 'rien à rattraper' && echo 0 || echo 1)

EMPREINTES_APRES=$(wp eval "
\$u = wp_upload_dir(); \$b = trailingslashit( \$u['basedir'] );
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) as \$l ) {
  \$f = \$b . \$l['chemin_harmonise'];
  echo \$l['taille'] . ':' . ( file_exists( \$f ) ? md5_file( \$f ) : 'x' ) . ':' . ( file_exists( \$f . '.webp' ) ? md5_file( \$f . '.webp' ) : 'x' ) . \"\n\";
}" | tr -d '\r' | sort)
verifier "aucun fichier modifié par le rattrapage à vide" \
  $([[ "$EMPREINTES_AVANT" == "$EMPREINTES_APRES" ]] && echo 0 || echo 1)

echo "--- cas 4 : harmonisée sans frère, rattrapée ---"
# On retire deux frères .webp à la main : c'est l'état exact de la production.
wp eval "
\$u = wp_upload_dir(); \$b = trailingslashit( \$u['basedir'] );
\$n = 0;
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) as \$l ) {
  \$w = \$b . \$l['chemin_harmonise'] . '.webp';
  if ( \$n < 2 && file_exists( \$w ) ) { unlink( \$w ); \$n++; }
}
echo \$n;" >/dev/null

SANS_AVANT=$(etat_harmonisees | grep -c 'SANS' || true)
verifier "deux frères .webp retirés pour le témoin (obtenu $SANS_AVANT)" $([[ "$SANS_AVANT" -eq 2 ]] && echo 0 || echo 1)

CIBLES=$(wp eval "echo implode( ',', ImagesLegeres_Harmonisation::ids_webp_manquant() );" | tr -d '\r')
verifier "le rattrapage désigne la pièce jointe $ID_IMAGE (obtenu '$CIBLES')" \
  $([[ ",$CIBLES," == *",$ID_IMAGE,"* ]] && echo 0 || echo 1)

echo "        -- dry-run : n'écrit rien --"
wp images-legeres harmoniser --webp-manquants --dry-run >/dev/null 2>&1
SANS_APRES_DRY=$(etat_harmonisees | grep -c 'SANS' || true)
verifier "--dry-run n'a rien écrit (toujours $SANS_APRES_DRY sans frère)" \
  $([[ "$SANS_APRES_DRY" -eq 2 ]] && echo 0 || echo 1)

echo "        -- rattrapage réel --"
wp images-legeres harmoniser --webp-manquants >/dev/null 2>&1
SANS_APRES=$(etat_harmonisees | grep -c 'SANS' || true)
verifier "plus aucune harmonisée sans frère après rattrapage (obtenu $SANS_APRES)" \
  $([[ "$SANS_APRES" -eq 0 ]] && echo 0 || echo 1)

RESTE=$(wp eval "echo count( ImagesLegeres_Harmonisation::ids_webp_manquant() );" | tr -d '\r')
verifier "rattrapage idempotent : plus rien à rattraper (obtenu $RESTE)" \
  $([[ "$RESTE" -eq 0 ]] && echo 0 || echo 1)

echo "--- cas 5 : la grande image du zoom ne dépasse pas 1 536 px ---"
GRANDE=$(wp eval "
\$g = ImagesLegeres_Harmonisees::plus_grandes_par_attachment( array( $ID_IMAGE ) );
echo isset( \$g[ $ID_IMAGE ] ) ? \$g[ $ID_IMAGE ]['largeur'] : 0;" | tr -d '\r')
MAX_DISPO=$(etat_harmonisees | awk -F'|' '{if ($2+0 > m) m = $2+0} END {print m+0}')
SOUS_PLAFOND_MAX=$(etat_harmonisees | awk -F'|' '$2+0 <= 1536 {if ($2+0 > m) m = $2+0} END {print m+0}')
echo "        plus grande disponible : ${MAX_DISPO}px, plus grande sous plafond : ${SOUS_PLAFOND_MAX}px, retenue : ${GRANDE}px"
verifier "la grande retenue ne dépasse pas 1 536 px (obtenu $GRANDE)" \
  $([[ "$GRANDE" -le 1536 && "$GRANDE" -gt 0 ]] && echo 0 || echo 1)
verifier "la grande retenue est la plus grande sous le plafond (attendu $SOUS_PLAFOND_MAX)" \
  $([[ "$GRANDE" -eq "$SOUS_PLAFOND_MAX" ]] && echo 0 || echo 1)

echo "--- cas 6 : repli quand aucune harmonisée ne tient sous le plafond ---"
REPLI=$(wp eval "
global \$wpdb;
\$t = ImagesLegeres_Harmonisees::nom();
\$garde = \$wpdb->get_results( \$wpdb->prepare( \"SELECT * FROM {\$t} WHERE attachment_id = %d\", $ID_IMAGE ), ARRAY_A );
\$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$t} WHERE attachment_id = %d AND largeur <= 1536\", $ID_IMAGE ) );
\$g = ImagesLegeres_Harmonisees::plus_grandes_par_attachment( array( $ID_IMAGE ) );
\$retenue = isset( \$g[ $ID_IMAGE ] ) ? (int) \$g[ $ID_IMAGE ]['largeur'] : 0;
\$restantes = (array) \$wpdb->get_col( \$wpdb->prepare( \"SELECT largeur FROM {\$t} WHERE attachment_id = %d\", $ID_IMAGE ) );
\$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$t} WHERE attachment_id = %d\", $ID_IMAGE ) );
foreach ( \$garde as \$l ) { unset( \$l['id'] ); \$wpdb->insert( \$t, \$l ); }
echo \$retenue . ' ' . ( \$restantes ? min( array_map( 'intval', \$restantes ) ) : 0 );" | tr -d '\r')
read -r REPLI_RETENUE REPLI_ATTENDU <<<"$REPLI"
verifier "sans harmonisée sous le plafond, la PLUS PETITE au-dessus est retenue (retenue $REPLI_RETENUE, attendue $REPLI_ATTENDU)" \
  $([[ "$REPLI_RETENUE" -eq "$REPLI_ATTENDU" && "$REPLI_RETENUE" -gt 0 ]] && echo 0 || echo 1)

echo "--- cas 7 : original déjà en WebP, portrait 3:4, avec puis sans rectangle ---"
BASE="http://localhost:${IL_HTTP_PORT:-8081}"
# Bleu au centre, vert sur les 150 px du haut et du bas : le carré centré est tout bleu, le
# carré cadré en haut (y = 0) garde le vert en haut.
creer_webp() {
  wp eval "
\$produit = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Bougie WebP $1 ${RUN_ID}', 'post_status' => 'publish' ) );
\$i = new Imagick();
\$i->newImage( 900, 1200, new ImagickPixel( 'blue' ) );
\$d = new ImagickDraw();
\$d->setFillColor( new ImagickPixel( 'green' ) );
\$d->rectangle( 0, 0, 899, 149 );
\$d->rectangle( 0, 1050, 899, 1199 );
\$i->drawImage( \$d );
\$i->setImageFormat( 'webp' );
\$u = wp_upload_dir();
\$dest = trailingslashit( \$u['path'] ) . 'webp-$1-${RUN_ID}.webp';
\$i->writeImage( \$dest );
\$id = wp_insert_attachment( array( 'post_mime_type' => 'image/webp', 'post_title' => 'webp $1', 'post_status' => 'inherit', 'post_parent' => \$produit ), \$dest );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( \$id, wp_generate_attachment_metadata( \$id, \$dest ) );
set_post_thumbnail( \$produit, \$id );
wp_update_post( array( 'ID' => \$produit, 'post_content' => wp_get_attachment_image( \$id, 'woocommerce_single' ) ) );
echo \$produit . ' ' . \$id;
" | tr -d '\r'
}

# « ok:<rangs>:<plus grand côté>:<haut>:<bas> » : chaque carré est un WebP, aux dimensions
# inventoriées, sans frère ; couleurs relevées sur le plus grand.
carres_webp() {
  wp eval "
\$u = wp_upload_dir(); \$b = trailingslashit( \$u['basedir'] ); \$ko = array(); \$max = 0; \$grand = '';
\$l = ImagesLegeres_Harmonisees::par_attachment( $1 );
foreach ( \$l as \$r ) {
  \$f = \$b . \$r['chemin_harmonise']; \$m = @getimagesize( \$f );
  if ( ! \$m || 'image/webp' !== \$m['mime'] || '.webp' !== substr( \$f, -5 ) || \$m[0] !== \$m[1] || \$m[0] !== (int) \$r['largeur'] || file_exists( \$f . '.webp' ) ) { \$ko[] = \$r['taille']; continue; }
  if ( \$m[0] > \$max ) { \$max = \$m[0]; \$grand = \$f; }
}
if ( ! \$l || \$ko ) { echo 'ko:' . implode( ',', \$ko ); return; }
\$i = new Imagick( \$grand );
\$teinte = function ( \$y ) use ( \$i, \$max ) { \$c = \$i->getImagePixelColor( 5, \$y )->getColor(); return \$c['b'] > 180 && \$c['g'] < 80 ? 'bleu' : ( \$c['g'] > 90 && \$c['b'] < 80 ? 'vert' : 'autre' ); };
echo 'ok:' . count( \$l ) . ':' . \$max . ':' . \$teinte( 5 ) . ':' . \$teinte( \$max - 6 );" | tr -d '\r'
}

# Adresses d'images des balises <img> de la fiche qui désignent ce fichier.
adresses_de_la_page() {
  curl -s "$BASE/?post_type=product&p=$1" | grep -oE '<img[^>]*>' \
    | grep -oE '(src|srcset|data-src|data-large_image)="[^"]*"' | cut -d'"' -f2 \
    | tr ',' '\n' | awk '{print $1}' | grep "/$2" | sort -u
}

verifier_page_webp() {
  local produit="$1" nom="$2" cas="$3" adresses natives code
  adresses=$(adresses_de_la_page "$produit" "$nom")
  natives=$(echo "$adresses" | grep -vc -- '-il-harmonise-[0-9]*x[0-9]*\.webp$' || true)
  verifier "$cas : la fiche sert les carrés WebP et eux seuls ($(echo "$adresses" | grep -c . ) adresse(s), $natives autre(s))" \
    $([[ -n "$adresses" && "$natives" -eq 0 ]] && echo 0 || echo 1)
  code=$(curl -s -o /dev/null -w '%{http_code}:%{content_type}' "$(echo "$adresses" | head -1)")
  verifier "$cas : le carré servi répond (obtenu $code)" $([[ "$code" == "200:image/webp" ]] && echo 0 || echo 1)
}

wp eval 'ImagesLegeres_Reglages::enregistrer(array("mode_apercu_actif" => false));' >/dev/null
read -r P_CADRE I_CADRE <<<"$(creer_webp cadre)"
read -r P_CENTRE I_CENTRE <<<"$(creer_webp centre)"
wp eval "ImagesLegeres_Recadrage::definir( $I_CADRE, 0, 0, 900, 900 );" >/dev/null
wp images-legeres convert "$I_CADRE" "$I_CENTRE" >/dev/null 2>&1
SORTIE=$(wp images-legeres harmoniser "$I_CADRE" "$I_CENTRE" 2>&1)
verifier "original WebP : plus ignoré par l'harmonisation" $(echo "$SORTIE" | grep -q 'déjà au format webp' && echo 1 || echo 0)

CADRE=$(carres_webp "$I_CADRE")
verifier "avec rectangle : carrés WebP aux bonnes dimensions, zone cadrée (obtenu $CADRE)" \
  $([[ "$CADRE" =~ ^ok:[0-9]+:[0-9]+:vert:bleu$ ]] && echo 0 || echo 1)
CENTRE=$(carres_webp "$I_CENTRE")
verifier "sans rectangle : carrés WebP centrés, aucune bande (obtenu $CENTRE)" \
  $([[ "$CENTRE" =~ ^ok:[0-9]+:[0-9]+:bleu:bleu$ ]] && echo 0 || echo 1)

verifier_page_webp "$P_CADRE" "webp-cadre-" "avec rectangle"
verifier_page_webp "$P_CENTRE" "webp-centre-" "sans rectangle"

SUITE=$(wp eval "
echo (int) ImagesLegeres_Harmonisation::a_jour( $I_CADRE ) . ':' . (int) ImagesLegeres_Harmonisation::a_jour( $I_CENTRE )
  . ':' . count( array_intersect( array( $I_CADRE, $I_CENTRE ), ImagesLegeres_Harmonisation::ids_webp_manquant() ) )
  . ':' . ImagesLegeres_Moteur::motif_exclusion( get_attached_file( $I_CADRE ) );" | tr -d '\r')
verifier "à jour, rien à rattraper, conversion générale toujours ignorée (obtenu $SUITE)" \
  $([[ "$SUITE" == "1:1:0:déjà au format webp" ]] && echo 0 || echo 1)

for i in "$I_CADRE" "$I_CENTRE"; do wp post delete "$i" --force >/dev/null 2>&1; done
for i in "$P_CADRE" "$P_CENTRE"; do wp post delete "$i" --force >/dev/null 2>&1; done

echo "--- nettoyage ---"
wp eval "ImagesLegeres_Harmonisation::supprimer( $ID_IMAGE );" >/dev/null 2>&1
wp post delete "$ID_IMAGE" --force >/dev/null 2>&1
wp post delete "$ID_PRODUIT" --force >/dev/null 2>&1

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
