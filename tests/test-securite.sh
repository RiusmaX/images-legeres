#!/usr/bin/env bash
# Tests des corrections du contrôle `security` (rapports qa/images-legeres/SECURITY.md et
# qa/images-legeres/repasse-1/SECURITY.md, constats SEC-1 à SEC-10). Un bloc par constat :
# il échoue si le défaut d'origine revient.
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
# Balayage BRUT de uploads, et non la liste bornée à la table : après tout_rendre() la
# table est supprimée, donc la liste bornée serait vide sans rien prouver (SEC-9).
APRES_TOUT_RENDRE=$(wp eval 'echo count( ImagesLegeres_Desinstallation::lister_tous_freres_webp() );' | tr -d '\r')
verifier "après « Tout rendre », plus aucun frère .webp sur le disque (obtenu $APRES_TOUT_RENDRE, attendu 0)" $([[ "${APRES_TOUT_RENDRE:-1}" -eq 0 ]] && echo 0 || echo 1)

ORPHELINS=$(wp images-legeres orphelins --format=csv 2>/dev/null | tail -n +2 | grep -c . || true)
echo "    orphelins restants : ${ORPHELINS:-0}"
verifier "le contrôle d'orphelins rend une liste vide après « Tout rendre » (obtenu ${ORPHELINS:-0})" $([[ "${ORPHELINS:-1}" -eq 0 ]] && echo 0 || echo 1)

echo
echo "=== Remise en état après tout_rendre (les blocs suivants ont besoin des tables) ==="
# tout_rendre() a supprimé les tables et les options : sans cette réactivation, les blocs
# SEC-8, SEC-9, SEC-10 et SEC-3 travailleraient sur une extension désinstallée.
wp plugin deactivate images-legeres >/dev/null 2>&1
wp plugin activate images-legeres >/dev/null 2>&1
wp eval '
ImagesLegeres_Reglages::enregistrer( array(
  "conversion_webp_active"    => true,
  "retaille_originaux_active" => true,
  "image_flux_active"         => true,
  "flux_google_actif"         => true,
  "flux_meta_actif"           => true,
) );
echo "pret";
' >/dev/null

echo
echo "=== SEC-8 — le crochet delete_attachment ne sort jamais du dossier uploads ==="
# Rejoue runs/2026-11-15-monko-wp-perf/preuves/security-8.sh : deux témoins déposés HORS de
# uploads, atteints par un « .. » dans _wp_attachment_metadata['sizes'][*]['file'] (cas A,
# borné au .webp) et dans _wp_attached_file (cas B, n'importe quelle extension, .php comprise).
SEC8=$(wp eval '
require_once ABSPATH . "wp-admin/includes/image.php";
require_once ABSPATH . "wp-admin/includes/file.php";
require_once ABSPATH . "wp-admin/includes/media.php";
$up = wp_get_upload_dir(); $base = $up["basedir"]; $hors = dirname( $base );
$faire = function( $nom ) {
  $im = new Imagick(); $im->newPseudoImage( 1200, 900, "plasma:fractal" );
  $im->setImageFormat( "jpeg" ); $im->writeImage( "/tmp/" . $nom . ".jpg" ); $im->clear();
  $p = wp_insert_post( array( "post_type" => "product", "post_title" => "[SEC] " . $nom, "post_status" => "publish" ) );
  $a = media_handle_sideload( array( "name" => $nom . ".jpg", "tmp_name" => "/tmp/" . $nom . ".jpg" ), $p, "[SEC] " . $nom );
  if ( is_wp_error( $a ) ) { return array( 0, 0 ); }
  foreach ( ImagesLegeres_Moteur::traiter_attachment( $a ) as $r ) {}
  return array( $a, $p );
};

list( $aA, $pA ) = $faire( "sec8a" );
$cibleA = $hors . "/temoin-sec8-hors-uploads.png.webp";
file_put_contents( $cibleA, "TEMOIN A" );
$m = wp_get_attachment_metadata( $aA );
$m["sizes"]["piege"] = array( "file" => "../../../temoin-sec8-hors-uploads.png", "width" => 1, "height" => 1, "mime-type" => "image/png" );
update_post_meta( $aA, "_wp_attachment_metadata", $m );

list( $aB, $pB ) = $faire( "sec8b" );
// Le cas B n exige que l existence du dossier originaux/, etat de tout site ayant passe un lot.
wp_mkdir_p( $base . "/images-legeres/originaux" );
$cibleB = $hors . "/temoin-sec8-hors-uploads.php";
file_put_contents( $cibleB, "<?php // TEMOIN B" );
update_post_meta( $aB, "_wp_attached_file", "../../../temoin-sec8-hors-uploads.php" );

clearstatcache( true );
ImagesLegeres_Cycle_Vie::supprimer_fichiers_produits( $aA );
ImagesLegeres_Cycle_Vie::supprimer_fichiers_produits( $aB );
clearstatcache( true );
$detruits = ( file_exists( $cibleA ) ? 0 : 1 ) + ( file_exists( $cibleB ) ? 0 : 1 );
@unlink( $cibleA ); @unlink( $cibleB );
wp_delete_post( $pA, true ); wp_delete_post( $pB, true );
echo "detruits_hors_uploads=" . $detruits;
' | tr -d '\r')
echo "    $SEC8"
verifier "aucun témoin hors uploads supprimé par delete_attachment (obtenu $SEC8, attendu detruits_hors_uploads=0)" $([[ "$SEC8" == "detruits_hors_uploads=0" ]] && echo 0 || echo 1)

SEC8B=$(wp eval '
$up = wp_get_upload_dir(); $hors = dirname( $up["basedir"] );
$ok = ( "" === ImagesLegeres_Moteur::chemin_relatif( $up["basedir"] . "/2026/../../../victime.png" ) ) ? 1 : 0;
$ok += ( "" === ImagesLegeres_Moteur::chemin_sauvegarde_original( $up["basedir"] . "/../../../victime.php" ) ) ? 1 : 0;
$ok += ( false === ImagesLegeres_Moteur::sous_uploads( $hors . "/ailleurs.jpg" ) ) ? 1 : 0;
$ok += ( true === ImagesLegeres_Moteur::sous_uploads( $up["basedir"] . "/2026/11/vrai.jpg" ) ) ? 1 : 0;
echo "gardes=" . $ok;
' | tr -d '\r')
echo "    $SEC8B"
verifier "les gardes de chemin refusent « .. » et le hors-uploads, acceptent un chemin légitime (obtenu $SEC8B, attendu gardes=4)" $([[ "$SEC8B" == "gardes=4" ]] && echo 0 || echo 1)

echo
echo "=== SEC-9 — « Tout rendre » n'efface pas les WebP d'un autre outil ==="
S9=$(creer_sujet "sec9-$$")
SEC9=$(wp eval '
$up = wp_get_upload_dir(); $base = trailingslashit( $up["basedir"] );
$tiers = $base . "tiers-ewww-sec9.jpg.webp";
file_put_contents( $tiers, "WEBP D UN AUTRE OUTIL" );
clearstatcache( true );
$connus = ImagesLegeres_Desinstallation::lister_freres_webp();
$liste_tiers = ImagesLegeres_Desinstallation::lister_freres_webp_tiers();
echo "connus=" . count( $connus );
echo " tiers_vu=" . ( in_array( $tiers, $liste_tiers, true ) ? "oui" : "non" );
echo " tiers_dans_les_connus=" . ( in_array( $tiers, $connus, true ) ? "OUI" : "non" );
ImagesLegeres_Desinstallation::supprimer_freres_webp();
clearstatcache( true );
echo " tiers_apres=" . ( file_exists( $tiers ) ? "present" : "SUPPRIME" );
@unlink( $tiers );
' | tr -d '\r')
echo "    $SEC9"
verifier "le WebP d'un autre outil n'est pas compté comme un frère de l'extension" $([[ "$SEC9" == *"tiers_dans_les_connus=non"* ]] && echo 0 || echo 1)
verifier "il est listé à part comme frère d'un autre outil" $([[ "$SEC9" == *"tiers_vu=oui"* ]] && echo 0 || echo 1)
verifier "« Tout rendre » le laisse sur le disque (obtenu ${SEC9##* }, attendu tiers_apres=present)" $([[ "$SEC9" == *"tiers_apres=present"* ]] && echo 0 || echo 1)
verifier "des frères connus de la table ont bien été trouvés à supprimer" $([[ "$SEC9" == "connus=0"* ]] && echo 1 || echo 0)

TIERS_ORPHELINS=$(wp eval '
$up = wp_get_upload_dir();
file_put_contents( trailingslashit( $up["basedir"] ) . "tiers-ewww-sec9b.jpg.webp", "X" );
echo "pret";
' >/dev/null; wp images-legeres orphelins --format=csv 2>/dev/null | grep -c "autre outil" || true)
verifier "le contrôle d'orphelins annonce les frères d'un autre outil sur une ligne à part (obtenu ${TIERS_ORPHELINS:-0}, attendu 1)" $([[ "${TIERS_ORPHELINS:-0}" -eq 1 ]] && echo 0 || echo 1)
wp eval '$up = wp_get_upload_dir(); @unlink( trailingslashit( $up["basedir"] ) . "tiers-ewww-sec9b.jpg.webp" );' >/dev/null

echo
echo "=== SEC-10 — la protection est re-posée à la mise à jour, pas seulement à l'activation ==="
SEC10=$(wp eval '
$up = wp_get_upload_dir();
$fichier = trailingslashit( $up["basedir"] ) . "images-legeres/.htaccess";
wp_mkdir_p( dirname( $fichier ) );
// Etat d un site mis a jour depuis une version anterieure : l ancienne regle, qui rendait
// le dossier flux/ en 403 pour Google Listings and Ads et Facebook for WooCommerce. Le
// temoin est le fichier ENTIER : la bonne regle contient elle aussi « Deny from all », dans
// son bloc de repli Apache 2.2.
$ancienne = "# version precedente\nDeny from all\n";
file_put_contents( $fichier, $ancienne );
update_option( "images_legeres_version", "0.0.1" );
ImagesLegeres_Plugin::verifier_version();
clearstatcache( true );
$contenu = file_exists( $fichier ) ? (string) file_get_contents( $fichier ) : "";
echo "ancienne_regle_restante=" . ( $contenu === $ancienne ? "OUI" : "non" );
echo " syntaxe_2_4=" . ( false !== strpos( $contenu, "Require all denied" ) ? "oui" : "NON" );
echo " version_remise=" . ( get_option( "images_legeres_version" ) === IMAGES_LEGERES_VERSION ? "oui" : "NON" );
' | tr -d '\r')
echo "    $SEC10"
verifier "la règle héritée est remplacée sans attendre une ligne de journal" $([[ "$SEC10" == *"ancienne_regle_restante=non"* ]] && echo 0 || echo 1)
verifier "la règle re-posée est bien celle en syntaxe Apache 2.4" $([[ "$SEC10" == *"syntaxe_2_4=oui"* ]] && echo 0 || echo 1)
verifier "la version enregistrée est remise à celle du fichier" $([[ "$SEC10" == *"version_remise=oui"* ]] && echo 0 || echo 1)

SEC10B=$(wp eval '
$up = wp_get_upload_dir();
$fichier = trailingslashit( $up["basedir"] ) . "images-legeres/.htaccess";
$ancienne = "# version precedente\nDeny from all\n";
file_put_contents( $fichier, $ancienne );
// Un autre plugin mis a jour ne doit rien declencher.
ImagesLegeres_Plugin::apres_mise_a_jour( null, array( "type" => "plugin", "action" => "update", "plugins" => array( "autre/autre.php" ) ) );
clearstatcache( true );
$apres_autre = ( (string) file_get_contents( $fichier ) === $ancienne ) ? "intacte" : "touchee";
ImagesLegeres_Plugin::apres_mise_a_jour( null, array( "type" => "plugin", "action" => "update", "plugins" => array( IMAGES_LEGERES_BASENAME ) ) );
clearstatcache( true );
$apres_nous = ( (string) file_get_contents( $fichier ) === $ancienne ) ? "NON_REPOSEE" : "reposee";
echo "autre_plugin=" . $apres_autre . " notre_plugin=" . $apres_nous;
' | tr -d '\r')
echo "    $SEC10B"
verifier "la mise à jour d'un autre plugin ne re-pose rien (obtenu ${SEC10B%% *})" $([[ "$SEC10B" == "autre_plugin=intacte"* ]] && echo 0 || echo 1)
verifier "la mise à jour de cette extension re-pose la protection (obtenu ${SEC10B##* })" $([[ "$SEC10B" == *"notre_plugin=reposee"* ]] && echo 0 || echo 1)

SEC10C=$(wp eval '
$up = wp_get_upload_dir();
$racine = trailingslashit( $up["basedir"] ) . "images-legeres";
// Etat d un site qui vient de rendre ses fichiers : le dossier n existe plus. La re-pose
// ne doit pas le recreer, sinon « orphelins » le signale a la requete suivante.
if ( is_dir( $racine ) ) {
  $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
  foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
  @rmdir( $racine );
}
delete_option( "images_legeres_version" );
clearstatcache( true );
ImagesLegeres_Plugin::verifier_version();
clearstatcache( true );
echo "dossier_ressuscite=" . ( is_dir( $racine ) ? "OUI" : "non" );
' | tr -d '\r')
echo "    $SEC10C"
verifier "la re-pose ne ressuscite pas le dossier supprimé par « Tout rendre » (obtenu $SEC10C)" $([[ "$SEC10C" == "dossier_ressuscite=non" ]] && echo 0 || echo 1)

echo
echo "=== SEC-3 — hors du jeton de taille Meta, aucune résolution n'est détournée ==="
S3=$(creer_sujet "sec3b-$$")
ATT3=$(echo "$S3" | awk '/^ATT /{print $2}')
SEC3=$(wp eval "
\$att = (int) '${ATT3}';
\$flux = ImagesLegeres_Flux::flux_existant( \$att );
ImagesLegeres_Flux::armer_drapeau_meta( 'full' );
\$apres_armement = wp_get_attachment_image_src( \$att, 'thumbnail' );
ImagesLegeres_Flux::armer_drapeau_meta( 'full' );
\$au_jeton = wp_get_attachment_image_src( \$att, ImagesLegeres_Flux::TAILLE_META );
ImagesLegeres_Flux::desarmer_drapeau_meta();
echo 'vignette_detournee=' . ( ( \$flux && \$apres_armement && \$apres_armement[0] === \$flux['url'] ) ? 'OUI' : 'non' );
echo ' jeton_detourne=' . ( ( \$flux && \$au_jeton && \$au_jeton[0] === \$flux['url'] ) ? 'oui' : 'NON' );
" | tr -d '\r')
echo "    $SEC3"
verifier "la vignette qui suit l'armement sort à sa taille normale (obtenu ${SEC3%% *})" $([[ "$SEC3" == "vignette_detournee=non"* ]] && echo 0 || echo 1)
verifier "le jeton de taille Meta reste bien détourné vers le fichier de flux (obtenu ${SEC3##* })" $([[ "$SEC3" == *"jeton_detourne=oui"* ]] && echo 0 || echo 1)

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
