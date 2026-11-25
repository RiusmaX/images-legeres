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
