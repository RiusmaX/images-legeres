#!/usr/bin/env bash
# F5 : le mode aperçu couvre l'harmonisation comme il couvre le WebP.
#
# Constat de production du 2026-11-15 : l'harmonisation écrivait la sous-taille sous le nom
# de fichier NATIF et occupait son entrée de `_wp_attachment_metadata`. Une régénération
# changeait donc les vignettes pour TOUS les visiteurs sans passer par la réécriture
# d'adresse — le mode aperçu ne protégeait plus rien. Cette suite vérifie les cinq cas de
# la mission, sur les pages réellement servies (HTTP, anonyme et administrateur), plus la
# couverture WebP de toutes les sous-tailles après un lot.
#
# Tourne depuis l'HÔTE (wp-cli exécuté avec le PHP du conteneur `wordpress`, qui a les
# délégués Imagick ; curl depuis l'hôte sur le port publié).
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides).
# Usage : IL_HTTP_PORT=8099 bash tests/test-apercu-harmonisation.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"

PORT="${IL_HTTP_PORT:-8081}"
BASE="http://localhost:${PORT}"

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
COOKIES=$(mktemp)
trap 'rm -f "$COOKIES"' EXIT

verifier() {
  local description="$1" resultat="$2"
  if [[ "$resultat" -eq 0 ]]; then
    echo "OK   - $description"
  else
    echo "FAIL - $description"
    ECHECS=$((ECHECS + 1))
  fi
}

# Adresses d'images d'uploads présentes dans une page, triées : c'est le seul contenu que
# la réécriture peut changer, et donc ce qu'on compare « octet pour octet ».
adresses_images() {
  grep -oE 'http://localhost:'"${PORT}"'/wp-content/uploads/[^"'"'"' ,]+\.(jpe?g|png)(\.webp)?' \
    | sort -u
}

page_anonyme() { curl -s "$1"; }
page_admin()   { curl -s -b "$COOKIES" "$1"; }

echo "--- préparation : réglages par défaut, harmonisation + aperçu actifs ---"
# WooCommerce s'installe en « bientôt disponible » tant que l'assistant n'est pas terminé :
# le visiteur anonyme reçoit la page d'attente, l'administrateur la vraie fiche. Toute la
# suite compare ce que reçoit un anonyme : sans cette boutique ouverte, elle mesure la page
# d'attente (F5, cas 3).
wp option update woocommerce_coming_soon no >/dev/null 2>&1
wp option update woocommerce_store_pages_only no >/dev/null 2>&1
wp eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts());' >/dev/null
wp eval 'ImagesLegeres_Reglages::enregistrer(array("harmonisation_produit_active" => true, "mode_apercu_actif" => true));' >/dev/null
wp option update permalink_structure '' >/dev/null 2>&1

SEUIL=$(wp eval 'echo ImagesLegeres_Reglages::valeur("gain_minimal_pourcent");' | tr -d '\r')
verifier "seuil de gain à 0 % (obtenu $SEUIL)" $([[ "$SEUIL" == "0" ]] && echo 0 || echo 1)

echo "--- connexion administrateur ---"
curl -s -c "$COOKIES" -b "$COOKIES" "$BASE/wp-login.php" >/dev/null
curl -s -L -c "$COOKIES" -b "$COOKIES" \
  --data-urlencode 'log=admin' --data-urlencode 'pwd=admin' \
  --data-urlencode 'wp-submit=Log In' --data-urlencode 'testcookie=1' \
  --data-urlencode "redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php" >/dev/null
EST_CONNECTE=$(page_admin "$BASE/wp-admin/" | grep -c 'wpadminbar\|wp-admin-bar' || true)
verifier "session administrateur ouverte" $([[ "$EST_CONNECTE" -gt 0 ]] && echo 0 || echo 1)

echo "--- produit témoin, photo 3:4 cadrée ---"
IDS=$(wp eval "
\$produit = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Bougie F5 ${RUN_ID}', 'post_status' => 'publish' ) );
\$i = new Imagick();
\$i->newPseudoImage( 1800, 2400, 'plasma:fractal' );
\$i->addNoiseImage( Imagick::NOISE_GAUSSIAN );
\$i->setImageFormat( 'jpeg' );
\$i->setImageCompressionQuality( 92 );
\$u = wp_upload_dir();
\$dest = trailingslashit( \$u['path'] ) . 'bougie-f5-${RUN_ID}.jpg';
\$i->writeImage( \$dest );
\$i->clear();
\$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'bougie f5', 'post_status' => 'inherit', 'post_parent' => \$produit ), \$dest );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( \$id, wp_generate_attachment_metadata( \$id, \$dest ) );
set_post_thumbnail( \$produit, \$id );
wp_update_post( array( 'ID' => \$produit, 'post_content' => wp_get_attachment_image( \$id, 'woocommerce_single' ) ) );
echo \$produit . ' ' . \$id;
" | tr -d '\r')
read -r ID_PRODUIT ID_IMAGE <<<"$IDS"
verifier "produit et image créés (produit $ID_PRODUIT, image $ID_IMAGE)" $([[ "$ID_IMAGE" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

wp eval "ImagesLegeres_Recadrage::definir( $ID_IMAGE, 0, 300, 1800, 1800 );" >/dev/null
RECTANGLE=$(wp eval "echo ImagesLegeres_Recadrage::obtenir( $ID_IMAGE ) ? 'pose' : 'absent';" | tr -d '\r')
verifier "rectangle 1800x1800 posé sur la photo 3:4" $([[ "$RECTANGLE" == "pose" ]] && echo 0 || echo 1)

# Permaliens simples : une fiche produit se demande par post_type + p, pas par ?p= seul.
URL_FICHE="$BASE/?post_type=product&p=$ID_PRODUIT"
URL_CATALOGUE="$BASE/?post_type=product"

echo "--- lot : conversion WebP de l'original et des sous-tailles ---"
wp images-legeres convert "$ID_IMAGE" >/dev/null 2>&1

# « Toute sous-taille a son WebP, sauf si le WebP pèserait plus lourd que l'original » :
# ce seul cas est admis, et il doit être tracé avec son motif propre (compté par `status`).
sous_tailles_sans_webp_injustifie() {
  wp eval "
\$m = wp_get_attachment_metadata( $1 );
\$d = trailingslashit( dirname( get_attached_file( $1 ) ) );
\$manquants = array();
foreach ( \$m['sizes'] as \$nom => \$t ) {
  if ( file_exists( \$d . \$t['file'] . '.webp' ) ) { continue; }
  \$ligne = ImagesLegeres_Table::par_chemin( ImagesLegeres_Moteur::chemin_relatif( \$d . \$t['file'] ) );
  \$justifie = \$ligne && 0 === strpos( \$ligne['motif'], ImagesLegeres_Moteur::MOTIF_WEBP_PLUS_LOURD );
  if ( ! \$justifie ) { \$manquants[] = \$nom . '(' . ( \$ligne ? \$ligne['motif'] : 'jamais traitée' ) . ')'; }
}
echo implode( ',', \$manquants );" | tr -d '\r'
}

SANS_WEBP=$(sous_tailles_sans_webp_injustifie "$ID_IMAGE")
verifier "après le lot, toute sous-taille a son .webp sauf WebP plus lourd (restes : '${SANS_WEBP:-aucun}')" $([[ -z "$SANS_WEBP" ]] && echo 0 || echo 1)

echo "--- sous-tailles créées APRÈS le lot : la pièce jointe repasse en file ---"
# Cause mesurée de la réserve « 87 ignorés » : rien ne redemandait une pièce jointe dont les
# sous-tailles avaient changé après le passage du lot.
wp eval "
add_image_size( 'il_f5_tardive', 640, 640, true );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $ID_IMAGE, wp_create_image_subsizes( get_attached_file( $ID_IMAGE ), $ID_IMAGE ) );" >/dev/null 2>&1
ETAT_FILE=$(wp eval "\$l = ImagesLegeres_File::ligne( $ID_IMAGE ); echo \$l ? \$l['etat'] : 'absente';" | tr -d '\r')
verifier "pièce jointe remise à « a-faire » après régénération (obtenu $ETAT_FILE)" $([[ "$ETAT_FILE" == "a-faire" ]] && echo 0 || echo 1)

wp images-legeres convert "$ID_IMAGE" >/dev/null 2>&1
SANS_WEBP2=$(sous_tailles_sans_webp_injustifie "$ID_IMAGE")
verifier "après repassage, la sous-taille tardive est traitée (restes : '${SANS_WEBP2:-aucun}')" $([[ -z "$SANS_WEBP2" ]] && echo 0 || echo 1)
TARDIVE=$(wp eval "
\$m = wp_get_attachment_metadata( $ID_IMAGE );
echo isset( \$m['sizes']['il_f5_tardive'] ) ? 'presente' : 'absente';" | tr -d '\r')
verifier "la sous-taille tardive existe bien dans les métadonnées (obtenu $TARDIVE)" $([[ "$TARDIVE" == "presente" ]] && echo 0 || echo 1)

echo "--- état avant harmonisation ---"
EMPREINTES_AVANT=$(wp eval "
\$m = wp_get_attachment_metadata( $ID_IMAGE );
\$d = trailingslashit( dirname( get_attached_file( $ID_IMAGE ) ) );
\$s = array( 'original:' . md5_file( get_attached_file( $ID_IMAGE ) ) );
foreach ( \$m['sizes'] as \$nom => \$t ) { \$s[] = \$nom . ':' . md5_file( \$d . \$t['file'] ); }
sort( \$s ); echo implode( ' ', \$s );" | tr -d '\r')
FICHE_ANON_AVANT=$(page_anonyme "$URL_FICHE" | adresses_images)
CATALOGUE_ANON_AVANT=$(page_anonyme "$URL_CATALOGUE" | adresses_images)
verifier "la fiche anonyme sert bien des images d'uploads avant harmonisation" $([[ -n "$FICHE_ANON_AVANT" ]] && echo 0 || echo 1)

echo "--- génération des sous-tailles harmonisées ---"
SORTIE_HARMONISER=$(wp images-legeres harmoniser "$ID_IMAGE" 2>&1)
NB_HARMONISEES=$(wp eval "echo count( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) );" | tr -d '\r')
verifier "sous-tailles harmonisées produites (obtenu ${NB_HARMONISEES:-0})" $([[ "${NB_HARMONISEES:-0}" -ge 1 ]] && echo 0 || echo 1)

# CE-1 : sans codec JPEG, `harmoniser` rend « NoDecodeDelegateForThisImageFormat » sur
# TOUTES les tailles et la recette ne mesure plus que le chemin d'erreur. On l'assèche ici :
# la sortie de la commande ne doit porter aucune erreur de décodage.
verifier "harmoniser ne rend aucune erreur de décodage d'image" \
  $(echo "$SORTIE_HARMONISER" | grep -qi 'NoDecodeDelegate\|NoEncodeDelegate\|ImagickException' && echo 1 || echo 0)

NOMS_DISTINCTS=$(wp eval "
\$ok = 1;
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) as \$l ) {
  if ( ! ImagesLegeres_Harmonisation::est_fichier_harmonise( \$l['chemin_harmonise'] ) ) { \$ok = 0; }
}
echo \$ok;" | tr -d '\r')
verifier "chaque harmonisée porte le suffixe réservé à l'extension" $([[ "$NOMS_DISTINCTS" == "1" ]] && echo 0 || echo 1)

PAS_DANS_META=$(wp eval "
\$m = wp_get_attachment_metadata( $ID_IMAGE );
\$sale = 0;
foreach ( \$m['sizes'] as \$t ) { if ( ImagesLegeres_Harmonisation::est_fichier_harmonise( \$t['file'] ) ) { \$sale = 1; } }
echo \$sale;" | tr -d '\r')
verifier "aucune harmonisée dans _wp_attachment_metadata['sizes']" $([[ "$PAS_DANS_META" == "0" ]] && echo 0 || echo 1)

echo "=== CAS 1 : aperçu actif, le visiteur anonyme ne voit rien changer ==="
EMPREINTES_APRES=$(wp eval "
\$m = wp_get_attachment_metadata( $ID_IMAGE );
\$d = trailingslashit( dirname( get_attached_file( $ID_IMAGE ) ) );
\$s = array( 'original:' . md5_file( get_attached_file( $ID_IMAGE ) ) );
foreach ( \$m['sizes'] as \$nom => \$t ) { \$s[] = \$nom . ':' . md5_file( \$d . \$t['file'] ); }
sort( \$s ); echo implode( ' ', \$s );" | tr -d '\r')
verifier "empreintes des fichiers natifs inchangées par l'harmonisation" $([[ "$EMPREINTES_AVANT" == "$EMPREINTES_APRES" ]] && echo 0 || echo 1)

FICHE_ANON_APRES=$(page_anonyme "$URL_FICHE" | adresses_images)
CATALOGUE_ANON_APRES=$(page_anonyme "$URL_CATALOGUE" | adresses_images)
verifier "fiche anonyme : adresses d'image identiques à l'avant" $([[ "$FICHE_ANON_AVANT" == "$FICHE_ANON_APRES" ]] && echo 0 || echo 1)
verifier "catalogue anonyme : adresses d'image identiques à l'avant" $([[ "$CATALOGUE_ANON_AVANT" == "$CATALOGUE_ANON_APRES" ]] && echo 0 || echo 1)
verifier "fiche anonyme : aucune adresse harmonisée" $(echo "$FICHE_ANON_APRES" | grep -q 'il-harmonise' && echo 1 || echo 0)
verifier "fiche anonyme : aucune adresse .webp" $(echo "$FICHE_ANON_APRES" | grep -q '\.webp' && echo 1 || echo 0)

echo "=== CAS 2 : administrateur connecté, adresses harmonisées servies ==="
FICHE_ADMIN=$(page_admin "$URL_FICHE")
ADRESSES_ADMIN=$(echo "$FICHE_ADMIN" | adresses_images)
verifier "fiche vue en administrateur : au moins une adresse harmonisée" $(echo "$ADRESSES_ADMIN" | grep -q 'il-harmonise' && echo 0 || echo 1)

CODES=""
TOUTES_200=0
for adresse in $(echo "$ADRESSES_ADMIN" | grep 'il-harmonise' || true); do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' "$adresse")
  CODES="$CODES $CODE"
  [[ "$CODE" != "200" ]] && TOUTES_200=1
done
verifier "toutes les adresses harmonisées répondent 200 (codes :$CODES)" $([[ -n "$CODES" && "$TOUTES_200" -eq 0 ]] && echo 0 || echo 1)

CARREES=$(wp eval "
\$ok = 1; \$vues = 0;
\$u = wp_upload_dir();
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) as \$l ) {
  \$c = trailingslashit( \$u['basedir'] ) . \$l['chemin_harmonise'];
  if ( ! file_exists( \$c ) ) { \$ok = 0; continue; }
  \$i = new Imagick( \$c );
  \$vues++;
  if ( \$i->getImageWidth() !== (int) \$l['largeur'] || \$i->getImageHeight() !== (int) \$l['hauteur'] || \$i->getImageWidth() !== \$i->getImageHeight() ) { \$ok = 0; }
  \$i->clear();
}
echo \$ok . ':' . \$vues;" | tr -d '\r')
# CE-1 : « 1:0 » (aucun fichier ouvert) passait pour un succès. Il faut un verdict vert ET
# au moins un fichier réellement relu par Imagick.
VUS=${CARREES##*:}
verifier "fichiers harmonisés aux dimensions carrées attendues (obtenu $CARREES)" \
  $([[ "$CARREES" == 1:* && "${VUS:-0}" -ge 1 ]] && echo 0 || echo 1)

echo "=== CAS 3 : aperçu désactivé, les visiteurs reçoivent les harmonisées ==="
wp eval 'ImagesLegeres_Reglages::enregistrer(array("mode_apercu_actif" => false));' >/dev/null

# Garde-fou : une page d'attente de boutique, ou tout autre gabarit sans galerie, ferait
# échouer les vérifications suivantes pour une raison qui n'est pas la réécriture.
PAGE_OUVERTE=$(page_anonyme "$URL_FICHE")
verifier "aperçu désactivé : l'anonyme reçoit bien la fiche produit (galerie présente)" \
  $(echo "$PAGE_OUVERTE" | grep -q 'woocommerce-product-gallery__image' && echo 0 || echo 1)

FICHE_OUVERTE=$(echo "$PAGE_OUVERTE" | adresses_images)
verifier "aperçu désactivé : la fiche anonyme sert les harmonisées" $(echo "$FICHE_OUVERTE" | grep -q 'il-harmonise' && echo 0 || echo 1)
verifier "aperçu désactivé : les harmonisées sont servies en .webp" $(echo "$FICHE_OUVERTE" | grep -q 'il-harmonise.*\.webp' && echo 0 || echo 1)

# Servies, pas seulement écrites dans le HTML : chaque adresse de l'extension répond 200 à
# une requête SANS cookie.
CODES_ANON=""
ANON_TOUTES_200=0
for adresse in $(echo "$FICHE_OUVERTE" | grep -E 'il-harmonise|\.webp' || true); do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' "$adresse")
  CODES_ANON="$CODES_ANON $CODE"
  [[ "$CODE" != "200" ]] && ANON_TOUTES_200=1
done
verifier "aperçu désactivé : adresses harmonisées et .webp servies 200 à l'anonyme (codes :$CODES_ANON)" \
  $([[ -n "$CODES_ANON" && "$ANON_TOUTES_200" -eq 0 ]] && echo 0 || echo 1)

# Retour au mode aperçu : le même anonyme doit retrouver exactement les adresses natives.
wp eval 'ImagesLegeres_Reglages::enregistrer(array("mode_apercu_actif" => true));' >/dev/null
FICHE_REFERMEE=$(page_anonyme "$URL_FICHE" | adresses_images)
verifier "aperçu remis : l'anonyme ne reçoit plus aucune harmonisée ni .webp" \
  $(echo "$FICHE_REFERMEE" | grep -qE 'il-harmonise|\.webp' && echo 1 || echo 0)
verifier "aperçu remis : l'anonyme reçoit toujours des adresses d'images natives" \
  $([[ -n "$FICHE_REFERMEE" ]] && echo 0 || echo 1)

echo "=== CAS 3 bis : le chemin lu en base est revalidé avant d'être servi (SEC-F5-1) ==="
# Audit du lot F4+F5 : `harmonisee()` servait `$baseurl . '/' . chemin_harmonise` sans aucun
# contrôle, là où la branche WebP voisine refuse « .. » et confine par realpath. On rejoue la
# mesure du rapport : ligne d'inventaire réécrite en base avec un chemin de traversée, aperçu
# coupé, requête ANONYME. Attendu : l'adresse d'origine, et pas la moindre trace de wp-config.
wp eval 'ImagesLegeres_Reglages::enregistrer(array("mode_apercu_actif" => false));' >/dev/null

NATIF_PIEGE=$(wp eval "
global \$wpdb;
\$lignes = ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE );
if ( empty( \$lignes ) ) { echo ''; return; }
\$l = \$lignes[0];
\$wpdb->update( ImagesLegeres_Harmonisees::nom(), array( 'chemin_harmonise' => '../../../wp-config.php' ), array( 'id' => (int) \$l['id'] ) );
echo \$l['chemin_natif'] . ' ' . \$l['chemin_harmonise'];
" | tr -d '\r')
read -r CHEMIN_NATIF_PIEGE CHEMIN_HARMONISE_PIEGE <<<"$NATIF_PIEGE"
verifier "ligne d'inventaire piégée posée en base (natif : ${CHEMIN_NATIF_PIEGE:-aucun})" \
  $([[ -n "$CHEMIN_NATIF_PIEGE" ]] && echo 0 || echo 1)

PAGE_PIEGEE=$(page_anonyme "$URL_FICHE")
verifier "chemin de traversée : aucune adresse « .. » servie à l'anonyme" \
  $(echo "$PAGE_PIEGEE" | grep -q 'uploads/\.\.' && echo 1 || echo 0)
verifier "chemin de traversée : « wp-config » absent du HTML servi à l'anonyme" \
  $(echo "$PAGE_PIEGEE" | grep -qi 'wp-config' && echo 1 || echo 0)
verifier "chemin de traversée : l'adresse d'origine est servie à la place" \
  $(echo "$PAGE_PIEGEE" | grep -qF "$CHEMIN_NATIF_PIEGE" && echo 0 || echo 1)

# Remise en état de l'inventaire : les cas suivants repartent d'un état sain.
wp eval "
global \$wpdb;
\$wpdb->update( ImagesLegeres_Harmonisees::nom(), array( 'chemin_harmonise' => '${CHEMIN_HARMONISE_PIEGE}' ), array( 'chemin_harmonise' => '../../../wp-config.php' ) );
" >/dev/null

echo "=== CAS 3 ter : une entrée de srcset harmonisée est substituée même sans harmonisée sur le src ==="
# Réserve « Pour code-quality » du même audit : la balise entière était laissée intacte dès
# que le `src` n'avait pas d'harmonisée, donc une entrée de `srcset` qui en avait une n'était
# jamais substituée. Le `src` (et width/height) suit la plus large des harmonisées gardées :
# laisser le natif en repli servirait un rectangle pendant que le srcset sert des carrés.
SRCSET_SUBSTITUE=$(wp eval "
\$u = wp_upload_dir();
\$base = untrailingslashit( \$u['baseurl'] );
\$lignes = ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE );
if ( empty( \$lignes ) ) { echo 'aucune-ligne'; return; }
\$avec = \$lignes[0];
\$sans = ltrim( str_replace( trailingslashit( \$u['basedir'] ), '', get_attached_file( $ID_IMAGE ) ), '/' );
\$html = '<img src=\"' . \$base . '/' . \$sans . '\" srcset=\"' . \$base . '/' . \$avec['chemin_natif'] . ' ' . (int) \$avec['largeur'] . 'w, ' . \$base . '/' . \$sans . ' 1800w\" width=\"1800\" height=\"2400\" />';
\$sortie = ImagesLegeres_Service::reecrire_tampon( \$html );
echo ( false !== strpos( \$sortie, \$avec['chemin_harmonise'] ) ? 'substitue' : 'intact' );
// La passe WebP suit la passe harmonisée : l'adresse gardée peut porter un « .webp » en
// plus. Ce qui compte ici, c'est qu'il reste UNE entrée, et que ce soit l'harmonisée.
\$srcset = preg_match( '#srcset=\"([^\"]*)\"#', \$sortie, \$m ) ? \$m[1] : '';
\$attendue = \$base . '/' . \$avec['chemin_harmonise'] . ' ' . (int) \$avec['largeur'] . 'w';
\$attendue_webp = \$base . '/' . \$avec['chemin_harmonise'] . '.webp ' . (int) \$avec['largeur'] . 'w';
echo ( ( \$srcset === \$attendue || \$srcset === \$attendue_webp ) ? ' srcset=propre' : ' srcset=sale[' . \$srcset . ']' );
echo ( false !== strpos( \$sortie, 'width=\"' . (int) \$avec['largeur'] . '\"' ) ? ' dimensions=suivies' : ' dimensions=natives' );
" | tr -d '\r')
echo "    $SRCSET_SUBSTITUE"
verifier "srcset : l'entrée harmonisée est substituée alors que le src n'en a pas" \
  $([[ "$SRCSET_SUBSTITUE" == substitue* ]] && echo 0 || echo 1)
verifier "srcset : seule l'entrée harmonisée est gardée (pas de mélange de rapports)" \
  $([[ "$SRCSET_SUBSTITUE" == *"srcset=propre"* ]] && echo 0 || echo 1)
verifier "srcset : width/height suivent la harmonisée retenue" \
  $([[ "$SRCSET_SUBSTITUE" == *"dimensions=suivies"* ]] && echo 0 || echo 1)

wp eval 'ImagesLegeres_Reglages::enregistrer(array("mode_apercu_actif" => true));' >/dev/null

echo "=== CAS 4 : extension désactivée, retour aux adresses natives ==="
wp eval 'ImagesLegeres_Reglages::enregistrer(array("mode_apercu_actif" => false));' >/dev/null
wp plugin deactivate images-legeres >/dev/null 2>&1
FICHE_SANS_PLUGIN=$(page_anonyme "$URL_FICHE" | adresses_images)
verifier "extension désactivée : aucune adresse harmonisée" $(echo "$FICHE_SANS_PLUGIN" | grep -q 'il-harmonise' && echo 1 || echo 0)
verifier "extension désactivée : aucune adresse .webp" $(echo "$FICHE_SANS_PLUGIN" | grep -q '\.webp' && echo 1 || echo 0)
verifier "extension désactivée : adresses natives identiques à l'avant" $([[ "$FICHE_SANS_PLUGIN" == "$FICHE_ANON_AVANT" ]] && echo 0 || echo 1)
wp plugin activate images-legeres >/dev/null 2>&1

EMPREINTES_DESACTIVE=$(wp eval "
\$m = wp_get_attachment_metadata( $ID_IMAGE );
\$d = trailingslashit( dirname( get_attached_file( $ID_IMAGE ) ) );
\$s = array( 'original:' . md5_file( get_attached_file( $ID_IMAGE ) ) );
foreach ( \$m['sizes'] as \$nom => \$t ) { \$s[] = \$nom . ':' . md5_file( \$d . \$t['file'] ); }
sort( \$s ); echo implode( ' ', \$s );" | tr -d '\r')
verifier "fichiers natifs intacts après désactivation" $([[ "$EMPREINTES_AVANT" == "$EMPREINTES_DESACTIVE" ]] && echo 0 || echo 1)

echo "=== CAS 5 : « Tout rendre » ne laisse aucun fichier de l'extension ==="
AVANT_RENDRE=$(wp eval "echo count( ImagesLegeres_Harmonisation::fichiers_existants() );" | tr -d '\r')
verifier "des fichiers harmonisés existent avant « Tout rendre » (obtenu $AVANT_RENDRE)" $([[ "${AVANT_RENDRE:-0}" -ge 1 ]] && echo 0 || echo 1)

wp eval 'ImagesLegeres_Desinstallation::tout_rendre();' >/dev/null 2>&1
wp plugin activate images-legeres >/dev/null 2>&1

RESTES=$(wp eval "
\$u = wp_upload_dir();
\$base = untrailingslashit( \$u['basedir'] );
\$restes = array();
\$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( \$base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
foreach ( \$it as \$f ) {
  if ( ! \$f->isFile() ) { continue; }
  if ( ImagesLegeres_Harmonisation::est_fichier_harmonise( \$f->getFilename() ) || preg_match( '#\.(?:jpe?g|png)\.webp\$#i', \$f->getFilename() ) ) {
    \$restes[] = \$f->getFilename();
  }
}
echo implode( ',', \$restes );" | tr -d '\r')
verifier "plus aucun fichier de l'extension dans uploads (restes : '${RESTES:-aucun}')" $([[ -z "$RESTES" ]] && echo 0 || echo 1)

ORPHELINS=$(wp images-legeres orphelins --format=csv 2>/dev/null | tail -n +2 | grep -v '^\s*$' || true)
verifier "wp images-legeres orphelins ne rend plus rien (obtenu : '${ORPHELINS:-vide}')" $([[ -z "$ORPHELINS" ]] && echo 0 || echo 1)

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
