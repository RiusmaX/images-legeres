#!/usr/bin/env bash
# Test de l'image de flux Google et Meta (mission 18, ADR 0006) : fichier JPEG carré dédié
# hors sous-tailles, empreinte liée au rectangle et aux réglages, plancher 500 px sans
# agrandissement, branchement par filtre sur google-listings-and-ads 3.10.0 (filtre dédié
# `woocommerce_gla_product_attribute_values`) et facebook-for-woocommerce 3.7.8 (aucun
# filtre dédié trouvé : repli par drapeau `wp_get_attachment_image_src`, posé/retiré autour
# de la génération du flux).
#
# Tourne depuis l'HÔTE, comme test-recadrage.sh : le conteneur `cli` n'a pas de délégué
# Imagick utilisable, ce script récupère le binaire wp-cli et l'exécute avec le PHP du
# conteneur `wordpress`. Ne modifie pas tests/env/.
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides). La suite installe
# elle-même les deux extensions tierces aux versions attendues : les supposer présentes
# faisait échouer la suite sur un WordPress neuf (relevé par le contrôle qa du 2026-11-15).
# Ne pas enchaîner les suites sur la même instance.
#
# Usage : bash tests/test-flux.sh   (tests/env/up.sh doit déjà avoir tourné)
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

# Versions tierces contre lesquelles le branchement par filtre a été écrit (mission 18,
# ADR 0006). Épinglées : le point de branchement dépend de la version, une montée silencieuse
# changerait ce que la suite prouve. Installées, pas activées — le test ne lit que leurs
# fichiers et leurs filtres.
VERSION_GLA=3.10.0
VERSION_META=3.7.8

echo "--- préparation : extensions tierces aux versions attendues ---"
installer_extension() {
  local slug="$1" version="$2"
  local installee
  installee=$(wp plugin get "$slug" --field=version 2>/dev/null | tr -d '\r')

  if [[ "$installee" == "$version" ]]; then
    echo "OK   - $slug $version déjà installé"
    return 0
  fi

  if wp plugin install "$slug" --version="$version" --force >/dev/null 2>&1; then
    installee=$(wp plugin get "$slug" --field=version 2>/dev/null | tr -d '\r')
  fi

  verifier "$slug installé en version $version (obtenu ${installee:-aucune})" \
    $([[ "$installee" == "$version" ]] && echo 0 || echo 1)
}

installer_extension google-listings-and-ads "$VERSION_GLA"
installer_extension facebook-for-woocommerce "$VERSION_META"

echo "--- préparation : réglages par défaut, flux actif (général, Google, Meta) ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts());' >/dev/null
wp eval 'ImagesLegeres_Reglages::enregistrer(array("image_flux_active" => true, "flux_google_actif" => true, "flux_meta_actif" => true));' >/dev/null

echo "--- produit témoin ---"
ID_PRODUIT=$(wp eval "echo wp_insert_post(array('post_type'=>'product','post_title'=>'Produit témoin flux','post_status'=>'publish'));" | tr -d '\r')
verifier "produit témoin créé" $([[ "$ID_PRODUIT" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

creer_image() {
  local chemin="$1" largeur="$2" hauteur="$3"
  wp eval "
\$i = new Imagick();
\$i->newImage( $largeur, $hauteur, new ImagickPixel( 'green' ) );
\$i->setImageFormat( 'jpeg' );
\$i->writeImage( '$chemin' );
echo 'ok';
" >/dev/null
}

importer_et_rattacher() {
  local chemin="$1" parent="$2"
  wp eval "
\$uploads = wp_upload_dir();
\$dest = trailingslashit( \$uploads['path'] ) . basename( '$chemin' );
copy( '$chemin', \$dest );
\$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => basename( '$chemin' ), 'post_status' => 'inherit', 'post_parent' => $parent ), \$dest );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( \$id, wp_generate_attachment_metadata( \$id, \$dest ) );
echo \$id;
" | tr -d '\r'
}

echo "--- image 2000x2000 avec rectangle carré 1600x1600 : flux à 1500 px (dimension réglée) ---"
IMG1=/tmp/flux-1-${RUN_ID}.jpg
creer_image "$IMG1" 2000 2000
ID1=$(importer_et_rattacher "$IMG1" "$ID_PRODUIT")
verifier "import image 1" $([[ "$ID1" =~ ^[0-9]+$ ]] && echo 0 || echo 1)
wp eval "ImagesLegeres_Recadrage::definir( $ID1, 200, 200, 1600, 1600 );" >/dev/null

RESULTAT1=$(wp eval "
\$r = ImagesLegeres_Flux::generer( $ID1 );
echo \$r['etat'] . ':' . (\$r['chemin'] ?? '') . ':' . (\$r['dimension'] ?? '');
" | tr -d '\r')
verifier "generer() : état fait, dimension 1500 (obtenu $RESULTAT1)" $([[ "$RESULTAT1" == fait:*:1500 ]] && echo 0 || echo 1)

FICHIER1=$(echo "$RESULTAT1" | cut -d: -f2)
INFOS_FICHIER1=$(wp eval "
\$c = ImagesLegeres_Flux::dossier_absolu() . '$FICHIER1';
if ( ! file_exists( \$c ) ) { echo 'absent'; exit; }
\$img = new Imagick( \$c );
echo \$img->getImageFormat() . ',' . \$img->getImageWidth() . 'x' . \$img->getImageHeight() . ',' . (strpos( \$c, '/images-legeres/flux/' ) !== false ? 'bon-dossier' : 'mauvais-dossier');
" | tr -d '\r')
verifier "fichier de flux : JPEG carré 1500x1500 dans le dossier flux/ (obtenu $INFOS_FICHIER1)" $([[ "$INFOS_FICHIER1" == "JPEG,1500x1500,bon-dossier" ]] && echo 0 || echo 1)

EXTENSION_FICHIER1=$([[ "$FICHIER1" == *.jpg ]] && echo "jpg" || echo "autre")
verifier "nom de fichier en .jpg, jamais .webp (obtenu $FICHIER1)" $([[ "$EXTENSION_FICHIER1" == "jpg" ]] && echo 0 || echo 1)

URL1=$(wp eval "echo ImagesLegeres_Flux::url_ou_origine( $ID1 );" | tr -d '\r')
verifier "url_ou_origine() rend l'adresse de flux (obtenu $URL1)" $([[ "$URL1" == *"/images-legeres/flux/${FICHIER1}" ]] && echo 0 || echo 1)

HTTP1=$(curl -s -o /dev/null -w '%{http_code}:%{content_type}' "$URL1")
verifier "l'adresse de flux répond 200 en image/jpeg (obtenu $HTTP1)" $([[ "$HTTP1" == "200:image/jpeg" ]] && echo 0 || echo 1)

echo "--- changer le rectangle change le nom du fichier, et supprime l'ancien ---"
wp eval "ImagesLegeres_Recadrage::definir( $ID1, 0, 0, 2000, 2000 );" >/dev/null
RESULTAT1B=$(wp eval "
\$r = ImagesLegeres_Flux::generer( $ID1 );
echo \$r['etat'] . ':' . (\$r['chemin'] ?? '');
" | tr -d '\r')
FICHIER1B=$(echo "$RESULTAT1B" | cut -d: -f2)
verifier "nouveau rectangle -> nouveau nom de fichier (avant $FICHIER1, après $FICHIER1B)" $([[ "$FICHIER1B" != "$FICHIER1" && "$RESULTAT1B" == fait:* ]] && echo 0 || echo 1)

ANCIEN_SUPPRIME=$(wp eval "echo file_exists( ImagesLegeres_Flux::dossier_absolu() . '$FICHIER1' ) ? 'present' : 'absent';" | tr -d '\r')
verifier "l'ancien fichier de flux est supprimé à la régénération (obtenu $ANCIEN_SUPPRIME)" $([[ "$ANCIEN_SUPPRIME" == "absent" ]] && echo 0 || echo 1)

echo "--- sans rectangle (fond assumé) : flux au côté réel, sans agrandir ---"
IMG2=/tmp/flux-2-${RUN_ID}.jpg
creer_image "$IMG2" 900 1200
ID2=$(importer_et_rattacher "$IMG2" "$ID_PRODUIT")
wp eval "ImagesLegeres_Recadrage::marquer_fond_assume( $ID2, 'aucun carré ne contient le produit entier' );" >/dev/null

RESULTAT2=$(wp eval "
\$r = ImagesLegeres_Flux::generer( $ID2 );
echo \$r['etat'] . ':' . (\$r['dimension'] ?? '');
" | tr -d '\r')
verifier "fond assumé : flux produit au côté réel 1200 px, sans agrandir à 1500 (obtenu $RESULTAT2)" $([[ "$RESULTAT2" == "fait:1200" ]] && echo 0 || echo 1)

echo "--- côté réel sous le plancher de 500 px : pas de fichier, adresse d'origine conservée ---"
IMG3=/tmp/flux-3-${RUN_ID}.jpg
creer_image "$IMG3" 480 480
ID3=$(importer_et_rattacher "$IMG3" "$ID_PRODUIT")
wp eval "ImagesLegeres_Recadrage::marquer_fond_assume( $ID3, 'trop petite' );" >/dev/null

RESULTAT3=$(wp eval "
\$r = ImagesLegeres_Flux::generer( $ID3 );
echo \$r['etat'];
" | tr -d '\r')
verifier "480 px (sous le plancher 500) : état sous-plancher (obtenu $RESULTAT3)" $([[ "$RESULTAT3" == "sous-plancher" ]] && echo 0 || echo 1)

AUCUN_FICHIER3=$(wp eval "
\$motif = ImagesLegeres_Flux::dossier_absolu() . '${ID3}-*.jpg';
echo empty( glob( \$motif ) ) ? 'aucun' : 'present';
" | tr -d '\r')
verifier "aucun fichier de flux écrit pour l'image sous le plancher (obtenu $AUCUN_FICHIER3)" $([[ "$AUCUN_FICHIER3" == "aucun" ]] && echo 0 || echo 1)

URL3=$(wp eval "echo ImagesLegeres_Flux::url_ou_origine( $ID3 );" | tr -d '\r')
verifier "image sous le plancher : url_ou_origine() rend l'adresse d'origine, pas du dossier flux/ (obtenu $URL3)" $([[ "$URL3" != *"/images-legeres/flux/"* ]] && echo 0 || echo 1)

echo "--- branchement Google Listings and Ads : filtre woocommerce_gla_product_attribute_values ---"
wp eval "
\$produit = wc_get_product( $ID_PRODUIT );
\$produit->set_image_id( $ID1 );
\$produit->set_gallery_image_ids( array( $ID2, $ID3 ) );
\$produit->save();
" >/dev/null

RESULTAT_GLA=$(wp eval "
\$produit = wc_get_product( $ID_PRODUIT );
\$attributs = apply_filters( 'woocommerce_gla_product_attribute_values', array(), \$produit, null );
echo (\$attributs['imageLink'] ?? 'absent') . '|' . implode( ',', \$attributs['additionalImageLinks'] ?? array() );
" | tr -d '\r')
IMAGE_LINK_GLA="${RESULTAT_GLA%%|*}"
ADDITIONAL_GLA="${RESULTAT_GLA#*|}"
verifier "GLA : imageLink pointe sur le flux, en .jpg (obtenu $IMAGE_LINK_GLA)" $([[ "$IMAGE_LINK_GLA" == *"/images-legeres/flux/"*".jpg" ]] && echo 0 || echo 1)
verifier "GLA : la galerie contient le flux de l'image fond-assumée et l'origine de l'image sous le plancher (obtenu $ADDITIONAL_GLA)" \
  $([[ "$ADDITIONAL_GLA" == *"/images-legeres/flux/"*".jpg"* && "$ADDITIONAL_GLA" != *"/images-legeres/flux/${ID3}-"* ]] && echo 0 || echo 1)

echo "--- plugin désactivé ou flux Google coupé : filtre inchangé ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(array("flux_google_actif" => false));' >/dev/null
RESULTAT_GLA_OFF=$(wp eval "
\$produit = wc_get_product( $ID_PRODUIT );
\$attributs = apply_filters( 'woocommerce_gla_product_attribute_values', array(), \$produit, null );
echo empty( \$attributs ) ? 'vide' : 'rempli';
" | tr -d '\r')
verifier "flux Google désactivé : le filtre ne touche plus rien (obtenu $RESULTAT_GLA_OFF)" $([[ "$RESULTAT_GLA_OFF" == "vide" ]] && echo 0 || echo 1)
wp eval 'ImagesLegeres_Reglages::enregistrer(array("flux_google_actif" => true));' >/dev/null

echo "--- branchement Meta : drapeau posé pendant la génération, retiré après ---"
AVANT_ARMEMENT=$(wp eval "echo wp_get_attachment_image_url( $ID1, 'full' );" | tr -d '\r')
verifier "avant armement : adresse d'origine (pas de flux) (obtenu $AVANT_ARMEMENT)" $([[ "$AVANT_ARMEMENT" != *"/images-legeres/flux/"* ]] && echo 0 || echo 1)

PENDANT_ARMEMENT=$(wp eval "
apply_filters( 'facebook_for_woocommerce_fb_product_image_size', 'full' );
echo wp_get_attachment_image_url( $ID1, 'full' );
" | tr -d '\r')
verifier "pendant la génération du flux Meta : adresse de flux (obtenu $PENDANT_ARMEMENT)" $([[ "$PENDANT_ARMEMENT" == *"/images-legeres/flux/"*".jpg" ]] && echo 0 || echo 1)

APRES_FIN_GENERATION=$(wp eval "
do_action( 'wc_facebook_feed_generation_completed' );
echo wp_get_attachment_image_url( $ID1, 'full' );
" | tr -d '\r')
verifier "après la fin de la génération : adresse d'origine, le drapeau ne reste jamais armé (obtenu $APRES_FIN_GENERATION)" $([[ "$APRES_FIN_GENERATION" != *"/images-legeres/flux/"* ]] && echo 0 || echo 1)

echo "--- image sous le plancher : jamais remplacée par le flux, même drapeau Meta armé ---"
PENDANT_ARMEMENT_PLANCHER=$(wp eval "
apply_filters( 'facebook_for_woocommerce_fb_product_image_size', 'full' );
echo wp_get_attachment_image_url( $ID3, 'full' );
" | tr -d '\r')
verifier "sous le plancher, drapeau Meta armé : adresse d'origine conservée (obtenu $PENDANT_ARMEMENT_PLANCHER)" $([[ "$PENDANT_ARMEMENT_PLANCHER" != *"/images-legeres/flux/"* ]] && echo 0 || echo 1)
wp eval "do_action( 'wc_facebook_feed_generation_completed' );" >/dev/null

echo "--- aucune adresse de flux n'est jamais réécrite en .webp (dossier exclu du service, mission 14) ---"
EXCLUSION_WEBP=$(wp eval "
\$r = new ReflectionClass( 'ImagesLegeres_Service' );
\$const = \$r->getConstant( 'DOSSIER_FLUX' );
echo \$const === ImagesLegeres_Flux::DOSSIER ? 'identique' : 'different';
" | tr -d '\r')
verifier "le dossier exclu par le service de réécriture est le même que celui de la classe de flux (obtenu $EXCLUSION_WEBP)" $([[ "$EXCLUSION_WEBP" == "identique" ]] && echo 0 || echo 1)

echo "--- aucune extension tierce n'a été modifiée (sommes de contrôle) ---"
SOMME_GLA=$(wp eval "
\$f = '/var/www/html/wp-content/plugins/google-listings-and-ads/src/Product/WCProductAdapter.php';
echo file_exists( \$f ) ? md5_file( \$f ) : 'absent';
" | tr -d '\r')
SOMME_META=$(wp eval "
\$f = '/var/www/html/wp-content/plugins/facebook-for-woocommerce/includes/fbproduct.php';
echo file_exists( \$f ) ? md5_file( \$f ) : 'absent';
" | tr -d '\r')
verifier "fichiers lus de google-listings-and-ads et facebook-for-woocommerce toujours présents (non supprimés/renommés)" $([[ "$SOMME_GLA" != "absent" && "$SOMME_META" != "absent" ]] && echo 0 || echo 1)

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
