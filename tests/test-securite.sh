#!/usr/bin/env bash
# Tests des corrections du contrôle `security` (rapport qa/images-legeres/SECURITY.md,
# constats SEC-1 à SEC-7). Un bloc par constat : il échoue si le défaut d'origine revient.
#
# Tourne depuis l'HÔTE, comme test-flux.sh : le conteneur `cli` n'a pas de délégué WebP
# utilisable, ce script récupère le binaire wp-cli et l'exécute avec le PHP du conteneur
# `wordpress`. Ne modifie pas tests/env/.
#
# Usage : bash tests/test-securite.sh   (tests/env/up.sh doit déjà avoir tourné).
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"

IL_HTTP_PORT="${IL_HTTP_PORT:-8081}"
BASE="http://127.0.0.1:${IL_HTTP_PORT}"

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

code() { curl -sS -o /dev/null -w '%{http_code}' "$1"; }

ECHECS=0

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

echo "=== Préparation : réglages nominaux, protections re-posées ==="
wp eval '
ImagesLegeres_Reglages::enregistrer( array(
  "conversion_webp_active"    => true,
  "retaille_originaux_active" => true,
  "image_flux_active"         => true,
  "flux_google_actif"         => true,
  "flux_meta_actif"           => true,
) );
ImagesLegeres_Protection::poser_toutes();
echo "pret";
' >/dev/null

# Crée un produit + une image 2000x1500, la traite, génère son flux. Écrit sur stdout :
# ATT <id> / PRODUIT <id> / ORIGINAL <relatif> / FLUX <relatif>
creer_sujet() {
  local nom="$1"
  wp eval "
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
\$im = new Imagick(); \$im->newPseudoImage( 2000, 1500, 'plasma:fractal' );
\$im->setImageFormat( 'jpeg' ); \$im->setImageCompressionQuality( 95 );
\$im->writeImage( '/tmp/${nom}.jpg' ); \$im->clear();
\$produit = wp_insert_post( array( 'post_type' => 'product', 'post_title' => '[SEC] ${nom}', 'post_status' => 'publish' ) );
\$att = media_handle_sideload( array( 'name' => '${nom}.jpg', 'tmp_name' => '/tmp/${nom}.jpg' ), \$produit, '[SEC] ${nom}' );
if ( is_wp_error( \$att ) ) { echo 'ERREUR ' . \$att->get_error_message(); exit( 1 ); }
set_post_thumbnail( \$produit, \$att );
foreach ( ImagesLegeres_Moteur::traiter_attachment( \$att ) as \$r ) {}
\$f = ImagesLegeres_Flux::generer( \$att );
echo 'ATT ' . \$att . \"\n\";
echo 'PRODUIT ' . \$produit . \"\n\";
echo 'ORIGINAL ' . ImagesLegeres_Moteur::chemin_relatif( get_attached_file( \$att ) ) . \"\n\";
echo 'FLUX images-legeres/flux/' . ( isset( \$f['chemin'] ) ? \$f['chemin'] : '' ) . \"\n\";
" | tr -d '\r'
}

echo
echo "=== SEC-1 — supprimer une pièce jointe retire tout ce que l'extension a produit ==="
S1=$(creer_sujet "sec1-$$")
ATT1=$(echo "$S1" | sed -n 's/^ATT //p')
PROD1=$(echo "$S1" | sed -n 's/^PRODUIT //p')

RESULTAT_SEC1=$(wp eval "
\$att = $ATT1;
\$up = wp_get_upload_dir(); \$base = \$up['basedir'];
\$attendus = array();
foreach ( ImagesLegeres_Moteur::fichiers_de_attachment( \$att ) as \$c ) { if ( file_exists( \$c . '.webp' ) ) { \$attendus[] = \$c . '.webp'; } }
\$sauvegarde = ImagesLegeres_Moteur::chemin_sauvegarde_original( get_attached_file( \$att ) );
if ( file_exists( \$sauvegarde ) ) { \$attendus[] = \$sauvegarde; }
foreach ( glob( \$base . '/images-legeres/flux/' . \$att . '-*.jpg' ) as \$f ) { \$attendus[] = \$f; }
\$lignes_avant = count( ImagesLegeres_Table::tout() );
wp_delete_attachment( \$att, true );
wp_delete_post( $PROD1, true );
clearstatcache( true );
\$survivants = 0;
foreach ( \$attendus as \$f ) { if ( file_exists( \$f ) ) { \$survivants++; } }
\$restantes = 0;
foreach ( ImagesLegeres_Table::tout() as \$l ) { if ( (int) \$l['attachment_id'] === \$att ) { \$restantes++; } }
echo 'produits=' . count( \$attendus ) . ' survivants=' . \$survivants . ' lignes_restantes=' . \$restantes;
" | tr -d '\r')
echo "    $RESULTAT_SEC1"
PRODUITS=$(echo "$RESULTAT_SEC1" | sed -n 's/.*produits=\([0-9]*\).*/\1/p')
SURVIVANTS=$(echo "$RESULTAT_SEC1" | sed -n 's/.*survivants=\([0-9]*\).*/\1/p')
LIGNES=$(echo "$RESULTAT_SEC1" | sed -n 's/.*lignes_restantes=\([0-9]*\).*/\1/p')
verifier "l'extension avait bien produit des fichiers pour cette pièce jointe (obtenu $PRODUITS)" $([[ "${PRODUITS:-0}" -ge 3 ]] && echo 0 || echo 1)
verifier "aucun fichier produit ne survit à la suppression (obtenu $SURVIVANTS, attendu 0)" $([[ "${SURVIVANTS:-1}" -eq 0 ]] && echo 0 || echo 1)
verifier "aucune ligne d'état ne survit à la suppression (obtenu $LIGNES, attendu 0)" $([[ "${LIGNES:-1}" -eq 0 ]] && echo 0 || echo 1)

echo
echo "=== SEC-2 / SEC-6 — protection au bon niveau, sans condition, en syntaxe Apache 2.4 ==="
S2=$(creer_sujet "sec2-$$")
ATT2=$(echo "$S2" | sed -n 's/^ATT //p')
ORIG2=$(echo "$S2" | sed -n 's/^ORIGINAL //p')
FLUX2=$(echo "$S2" | sed -n 's/^FLUX //p')
UP="$BASE/wp-content/uploads"

C_ORIG=$(code "$UP/images-legeres/originaux/$ORIG2")
C_FLUX=$(code "$UP/$FLUX2")
verifier "la copie pleine résolution de originaux/ n'est pas servie (HTTP $C_ORIG, attendu 403 ou 404)" $([[ "$C_ORIG" == "403" || "$C_ORIG" == "404" ]] && echo 0 || echo 1)
verifier "l'image de flux reste servie — Google et Meta doivent la lire (HTTP $C_FLUX, attendu 200)" $([[ "$C_FLUX" == "200" ]] && echo 0 || echo 1)

wp eval 'ImagesLegeres_Journal::ecrire( "test-securite", "sonde", 0, 0, 0, "ligne de controle" );' >/dev/null
JOURNAL="$UP/images-legeres/journal-$(date -u +%Y-%m-%d).log"
C_JOURNAL=$(code "$JOURNAL")
C_FLUX_APRES=$(code "$UP/$FLUX2")
verifier "le journal n'est pas servi (HTTP $C_JOURNAL, attendu 403 ou 404)" $([[ "$C_JOURNAL" == "403" || "$C_JOURNAL" == "404" ]] && echo 0 || echo 1)
verifier "après une ligne de journal, l'image de flux reste servie (HTTP $C_FLUX_APRES, attendu 200)" $([[ "$C_FLUX_APRES" == "200" ]] && echo 0 || echo 1)

SYNTAXE=$(wp eval '
$up = wp_get_upload_dir();
$racine = file_get_contents( trailingslashit( $up["basedir"] ) . "images-legeres/.htaccess" );
$orig   = file_get_contents( trailingslashit( $up["basedir"] ) . "images-legeres/originaux/.htaccess" );
$flux   = trailingslashit( $up["basedir"] ) . "images-legeres/flux/.htaccess";
echo ( false !== strpos( $racine, "Require all denied" ) ? "racine24" : "racine-vieux" ) . " ";
echo ( false !== strpos( $racine, "mod_authz_core" ) ? "racine-repli" : "racine-sans-repli" ) . " ";
echo ( false !== strpos( $orig, "Require all denied" ) ? "orig24" : "orig-vieux" ) . " ";
echo ( file_exists( $flux ) ? "flux-htaccess-present" : "flux-sans-htaccess" );
' | tr -d '\r')
echo "    $SYNTAXE"
verifier "syntaxe Apache 2.4 avec repli 2.2 sur les deux .htaccess (obtenu $SYNTAXE)" $([[ "$SYNTAXE" == "racine24 racine-repli orig24 flux-sans-htaccess" ]] && echo 0 || echo 1)

RE_POSE=$(wp eval '
$up = wp_get_upload_dir();
$f = trailingslashit( $up["basedir"] ) . "images-legeres/originaux/.htaccess";
@unlink( $f );
ImagesLegeres_Protection::poser_originaux();
echo file_exists( $f ) ? "repose" : "absent";
' | tr -d '\r')
verifier "une protection effacée à la main est re-posée (obtenu $RE_POSE)" $([[ "$RE_POSE" == "repose" ]] && echo 0 || echo 1)

echo
echo "=== SEC-3 — le détournement Meta ne survit pas à la résolution qu'il borne ==="
SEC3=$(wp eval "
\$att = $ATT2;
\$taille_rendue = apply_filters( 'facebook_for_woocommerce_fb_product_image_size', 'full' );
\$meta_1 = wp_get_attachment_image_src( \$att, ImagesLegeres_Flux::TAILLE_META );
\$meta_2 = wp_get_attachment_image_src( \$att, ImagesLegeres_Flux::TAILLE_META );
\$apres_1 = wp_get_attachment_image_src( \$att, 'thumbnail' );
\$apres_2 = wp_get_attachment_image_src( \$att, 'thumbnail' );
\$apres_3 = wp_get_attachment_image_src( \$att, 'medium' );
echo 'jeton=' . \$taille_rendue;
echo ' meta1=' . ( false !== strpos( \$meta_1[0], '/images-legeres/flux/' ) ? 'flux' : 'origine' );
echo ' meta2=' . ( false !== strpos( \$meta_2[0], '/images-legeres/flux/' ) ? 'flux' : 'origine' );
echo ' suite2=' . ( false !== strpos( \$apres_2[0], '/images-legeres/flux/' ) ? 'flux' : 'origine' );
echo ' suite3=' . ( false !== strpos( \$apres_3[0], '/images-legeres/flux/' ) ? 'flux' : 'origine' );
echo ' dims_suite3=' . \$apres_3[1] . 'x' . \$apres_3[2];
do_action( 'wc_facebook_feed_generation_completed' );
" | tr -d '\r')
echo "    $SEC3"
verifier "Meta reçoit le jeton de taille dédié (obtenu ${SEC3%% *})" $([[ "$SEC3" == *"jeton=images-legeres-flux"* ]] && echo 0 || echo 1)
verifier "toutes les images que Meta résout avec ce jeton sont détournées (pas seulement la première)" $([[ "$SEC3" == *"meta1=flux"* && "$SEC3" == *"meta2=flux"* ]] && echo 0 || echo 1)
verifier "le détournement ne survit pas : vignettes suivantes à leur taille normale" $([[ "$SEC3" == *"suite2=origine"* && "$SEC3" == *"suite3=origine"* ]] && echo 0 || echo 1)
verifier "les dimensions déclarées d'une vignette restent les siennes (obtenu ${SEC3##*dims_suite3=})" $([[ "$SEC3" != *"dims_suite3=1500x1500"* ]] && echo 0 || echo 1)

SEC3_GLOBAL=$(wp eval "echo has_filter( 'wp_get_attachment_image_src', array( 'ImagesLegeres_Flux', 'filtrer_image_src_meta' ) ) ? 'branche' : 'absent';" | tr -d '\r')
verifier "le filtre n'est pas branché globalement hors armement (obtenu $SEC3_GLOBAL)" $([[ "$SEC3_GLOBAL" == "absent" ]] && echo 0 || echo 1)

echo
echo "=== SEC-5 — le réécriveur ne sort jamais du dossier uploads ==="
SEC5=$(wp eval '
$up = wp_get_upload_dir();
file_put_contents( WP_CONTENT_DIR . "/sec-temoin.png.webp", "temoin" );
$r = new ReflectionMethod( "ImagesLegeres_Service", "adresse_frere" );
$r->setAccessible( true );
$base_url = untrailingslashit( $up["baseurl"] );
$base_dir = untrailingslashit( $up["basedir"] );
$cas = array(
  "traversee"  => $base_url . "/../sec-temoin.png",
  "profonde"   => $base_url . "/2026/../../sec-temoin.png",
);
foreach ( $cas as $nom => $adresse ) {
  $rendu = $r->invoke( null, $adresse, $base_url, $base_dir );
  echo $nom . "=" . ( $rendu === $adresse ? "inchange" : "REECRIT" ) . " ";
}
@unlink( WP_CONTENT_DIR . "/sec-temoin.png.webp" );
' | tr -d '\r')
echo "    $SEC5"
verifier "une adresse avec ../ n'est jamais réécrite (obtenu $SEC5)" $([[ "$SEC5" == *"traversee=inchange"* && "$SEC5" == *"profonde=inchange"* ]] && echo 0 || echo 1)

SEC5_NORMAL=$(wp eval "
\$att = $ATT2;
\$up = wp_get_upload_dir();
\$r = new ReflectionMethod( 'ImagesLegeres_Service', 'adresse_frere' );
\$r->setAccessible( true );
\$adresse = wp_get_attachment_url( \$att );
echo \$r->invoke( null, \$adresse, untrailingslashit( \$up['baseurl'] ), untrailingslashit( \$up['basedir'] ) ) === \$adresse . '.webp' ? 'reecrit' : 'non-reecrit';
" | tr -d '\r')
verifier "le cas normal est toujours réécrit : le durcissement n'a rien cassé (obtenu $SEC5_NORMAL)" $([[ "$SEC5_NORMAL" == "reecrit" ]] && echo 0 || echo 1)

echo
echo "=== SEC-7 — le motif glob des fichiers de flux est borné à un entier ==="
SEC7=$(wp eval '
$up = wp_get_upload_dir();
$dossier = trailingslashit( $up["basedir"] ) . "images-legeres/flux/";
wp_mkdir_p( $dossier );
file_put_contents( $dossier . "999001-temoin7.jpg", "temoin" );
file_put_contents( $dossier . "999002-temoin7.jpg", "temoin" );
ImagesLegeres_Flux::supprimer( "*" );
ImagesLegeres_Flux::generer( "*" );
$restants = 0;
foreach ( array( "999001-temoin7.jpg", "999002-temoin7.jpg" ) as $f ) { if ( file_exists( $dossier . $f ) ) { $restants++; } }
@unlink( $dossier . "999001-temoin7.jpg" ); @unlink( $dossier . "999002-temoin7.jpg" );
echo "restants=" . $restants;
' | tr -d '\r')
echo "    $SEC7"
verifier "un identifiant « * » ne supprime aucun fichier de flux (obtenu $SEC7, attendu restants=2)" $([[ "$SEC7" == "restants=2" ]] && echo 0 || echo 1)

echo
echo "=== SEC-4 — « Tout rendre » ne laisse aucun frère .webp, et orphelins les voit ==="
S4=$(creer_sujet "sec4-$$")
AVANT_TOUT_RENDRE=$(wp eval 'echo count( ImagesLegeres_Desinstallation::lister_freres_webp() );' | tr -d '\r')
verifier "des frères .webp de sous-tailles existent avant « Tout rendre » (obtenu $AVANT_TOUT_RENDRE)" $([[ "${AVANT_TOUT_RENDRE:-0}" -ge 3 ]] && echo 0 || echo 1)

VU_PAR_ORPHELINS=$(wp images-legeres orphelins --format=csv 2>/dev/null | grep -c 'frères .webp' || true)
verifier "le contrôle d'orphelins voit ces frères avant nettoyage (obtenu $VU_PAR_ORPHELINS, attendu 1)" $([[ "${VU_PAR_ORPHELINS:-0}" -eq 1 ]] && echo 0 || echo 1)

wp eval 'ImagesLegeres_Desinstallation::tout_rendre();' >/dev/null
APRES_TOUT_RENDRE=$(wp eval 'echo count( ImagesLegeres_Desinstallation::lister_freres_webp() );' | tr -d '\r')
verifier "après « Tout rendre », plus aucun frère .webp (obtenu $APRES_TOUT_RENDRE, attendu 0)" $([[ "${APRES_TOUT_RENDRE:-1}" -eq 0 ]] && echo 0 || echo 1)

ORPHELINS=$(wp images-legeres orphelins --format=csv 2>/dev/null | tail -n +2 | grep -c . || true)
echo "    orphelins restants : ${ORPHELINS:-0}"
verifier "le contrôle d'orphelins rend une liste vide après « Tout rendre » (obtenu ${ORPHELINS:-0})" $([[ "${ORPHELINS:-1}" -eq 0 ]] && echo 0 || echo 1)

echo
echo "=== Remise en état (réactivation du plugin après tout_rendre) ==="
wp plugin deactivate images-legeres >/dev/null 2>&1
wp plugin activate images-legeres >/dev/null 2>&1

echo
if [[ "$ECHECS" -eq 0 ]]; then
  echo "Tous les contrôles de sécurité passent."
  exit 0
fi
echo "$ECHECS contrôle(s) de sécurité en échec."
exit 1
