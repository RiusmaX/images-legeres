#!/usr/bin/env bash
# Test exécuté dans le conteneur `cli` de tests/env/compose.yml :
#   docker compose -f tests/env/compose.yml run --rm cli bash /var/www/html/wp-content/plugins/images-legeres/tests/test-activation.sh
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides) : la suite
# désinstalle l'extension et vérifie qu'il ne reste aucun orphelin. Ne pas enchaîner les
# suites sur la même instance. Contrairement aux cinq autres, celle-ci tourne DANS le
# conteneur `cli` : la lancer depuis l'hôte la fait échouer intégralement, faute de `wp`.
# Depuis CE-1 (audit du lot F4+F5), ce conteneur embarque le codec JPEG d'ImageMagick
# (tests/env/Dockerfile.cli) : elle y exerce donc aussi `convert` et `harmoniser` sur une
# vraie image, et non plus seulement leur chemin d'erreur.
#
# Vérifie : activation/désactivation sans erreur PHP, table créée puis supprimée au niveau
# « tout rendre », aucun drop-in posé, `wp images-legeres orphelins` vide après désinstallation,
# réglage « mode aperçu » actif par défaut et lisible par `status`.
set -uo pipefail

WP="wp --allow-root --path=/var/www/html"
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

echo "--- vidage du journal de debug ---"
: > /var/www/html/wp-content/debug.log 2>/dev/null || true

echo "--- activation ---"
$WP plugin activate images-legeres
verifier "activation sans erreur WP-CLI" $?

if $WP db query "SHOW TABLES LIKE '%imageslegeres_fichiers'" | grep -q imageslegeres_fichiers; then
  verifier "table créée à l'activation" 0
else
  verifier "table créée à l'activation" 1
fi

MODE_APERCU=$($WP images-legeres status --format=json | php -r 'echo json_decode(file_get_contents("php://stdin"))[1]->valeur;')
if [[ "$MODE_APERCU" == "1" ]]; then
  verifier "mode aperçu actif par défaut (status)" 0
else
  verifier "mode aperçu actif par défaut (status)" 1
fi

echo "--- désactivation ---"
$WP plugin deactivate images-legeres
verifier "désactivation sans erreur WP-CLI" $?

if $WP db query "SHOW TABLES LIKE '%imageslegeres_fichiers'" | grep -q imageslegeres_fichiers; then
  verifier "table conservée après simple désactivation" 0
else
  verifier "table conservée après simple désactivation" 1
fi

echo "--- travail réel avant désinstallation (CE-1 : le conteneur cli décode le JPEG) ---"
$WP plugin activate images-legeres >/dev/null
$WP eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts()); ImagesLegeres_Reglages::enregistrer(array("harmonisation_produit_active" => true, "retaille_originaux_active" => true, "dimension_max_nouveaux_envois" => 900, "image_flux_active" => true));' >/dev/null

ID_IMAGE=$($WP eval '
$produit = wp_insert_post( array( "post_type" => "product", "post_title" => "activation", "post_status" => "publish" ) );
$i = new Imagick();
$i->newImage( 1600, 2000, new ImagickPixel( "#336699" ) );
$i->setImageFormat( "jpeg" );
$u = wp_upload_dir();
$dest = trailingslashit( $u["path"] ) . "activation-" . wp_generate_password( 6, false, false ) . ".jpg";
$i->writeImage( $dest );
$i->clear();
$id = wp_insert_attachment( array( "post_mime_type" => "image/jpeg", "post_title" => "activation", "post_status" => "inherit", "post_parent" => $produit ), $dest );
require_once ABSPATH . "wp-admin/includes/image.php";
wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
set_post_thumbnail( $produit, $id );
echo $id;' | tr -d '\r')
verifier "image JPEG créée par Imagick dans le conteneur cli (pièce jointe $ID_IMAGE)" $([[ "$ID_IMAGE" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

$WP eval "ImagesLegeres_Recadrage::definir( $ID_IMAGE, 0, 200, 1600, 1600 );" >/dev/null
$WP images-legeres convert "$ID_IMAGE" >/dev/null 2>&1
SORTIE_HARMONISER=$($WP images-legeres harmoniser "$ID_IMAGE" 2>&1)
verifier "harmoniser ne rend aucune erreur de décodage dans le conteneur cli" \
  $(echo "$SORTIE_HARMONISER" | grep -qi 'NoDecodeDelegate\|NoEncodeDelegate\|ImagickException' && echo 1 || echo 0)

CARRES=$($WP eval "
\$ok = 1; \$vus = 0;
\$u = wp_upload_dir();
foreach ( ImagesLegeres_Harmonisees::par_attachment( $ID_IMAGE ) as \$l ) {
  \$c = trailingslashit( \$u['basedir'] ) . \$l['chemin_harmonise'];
  if ( ! file_exists( \$c ) ) { \$ok = 0; continue; }
  \$im = new Imagick( \$c );
  \$vus++;
  if ( \$im->getImageWidth() !== \$im->getImageHeight() ) { \$ok = 0; }
  \$im->clear();
}
echo \$ok . ':' . \$vus;" | tr -d '\r')
VUS=${CARRES##*:}
verifier "harmoniser produit de vrais carrés dans le conteneur cli (obtenu $CARRES)" \
  $([[ "$CARRES" == 1:* && "${VUS:-0}" -ge 1 ]] && echo 0 || echo 1)

echo "--- migration de réglage sans changement de version (I1b, 2026-11-15) ---"
# Reconstitue l'état exact de la production : une mise à jour déposée par Git, même numéro
# de version avant et après (0.1.0 → 0.1.0), un seuil de gain hérité à 10 %, et aucun
# registre de migrations. `verifier_version()` comparait deux chaînes identiques et ne
# déclenchait rien : le seuil est resté à 10 % et a laissé 87 sous-tailles sans WebP.
# Pose ET relecture dans le MÊME appel : relire par un second `wp eval` rejouerait
# `plugins_loaded`, donc les migrations, avant d'avoir rien pu observer.
ETAT_AVANT=$($WP eval '
$r = ImagesLegeres_Reglages::tout();
$r["gain_minimal_pourcent"] = 10;
unset( $r[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] );
update_option( ImagesLegeres_Reglages::OPTION, $r );
// Version enregistrée IDENTIQUE à celle du fichier : rien ne doit dépendre d un écart.
update_option( "images_legeres_version", IMAGES_LEGERES_VERSION );

$relu = get_option( ImagesLegeres_Reglages::OPTION );
echo (int) $relu["gain_minimal_pourcent"] . ":" . ( isset( $relu[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] ) ? "registre" : "sans-registre" );' | tr -d '\r')

echo "état avant la requête suivante (attendu 10:sans-registre) : $ETAT_AVANT"
verifier "seuil hérité à 10 % posé, registre absent" \
  $([[ "$ETAT_AVANT" == "10:sans-registre" ]] && echo 0 || echo 1)

# Requête suivante : `plugins_loaded` rejoue `ImagesLegeres_Plugin::demarrer()`, donc les
# migrations. Aucun changement de version entre les deux.
MIGRATION=$($WP eval '
$r = get_option( ImagesLegeres_Reglages::OPTION );
$registre = isset( $r[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] ) ? (array) $r[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] : array();
echo (int) $r["gain_minimal_pourcent"] . ":"
  . ( in_array( "gain-minimal-zero-2026-11-15", $registre, true ) ? "inscrite" : "absente" ) . ":"
  . ( get_option( "images_legeres_version" ) === IMAGES_LEGERES_VERSION ? "version-inchangee" : "version-changee" );' | tr -d '\r')
echo "après une simple requête (attendu 0:inscrite:version-inchangee) : $MIGRATION"
verifier "la migration part sans changement de numéro de version" \
  $([[ "$MIGRATION" == "0:inscrite:version-inchangee" ]] && echo 0 || echo 1)

# Le registre vit DANS l'option de réglages : il doit survivre à un enregistrement des
# réglages par l'écran d'administration, qui reconstruit le tableau depuis `defauts()`.
REGISTRE_APRES_SAUVEGARDE=$($WP eval '
ImagesLegeres_Reglages::enregistrer( ImagesLegeres_Reglages::defauts() );
$r = get_option( ImagesLegeres_Reglages::OPTION );
$registre = isset( $r[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] ) ? (array) $r[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] : array();
echo in_array( "gain-minimal-zero-2026-11-15", $registre, true ) ? "conserve" : "PERDU";' | tr -d '\r')
verifier "le registre survit à un enregistrement des réglages (obtenu $REGISTRE_APRES_SAUVEGARDE)" \
  $([[ "$REGISTRE_APRES_SAUVEGARDE" == "conserve" ]] && echo 0 || echo 1)

# L'image de flux, pour que `_images_legeres_flux` soit écrite elle aussi : le contrôle
# d'après ne vaut que sur les clés réellement posées.
$WP eval "ImagesLegeres_Flux::generer( $ID_IMAGE );" >/dev/null 2>&1

# Les trois métas que l'extension écrit doivent exister avant la désinstallation, sinon le
# contrôle d'après ne prouve rien.
METAS_AVANT=$($WP eval '
global $wpdb;
$sales = array();
foreach ( (array) $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}" ) as $cle ) {
  if ( false !== strpos( $cle, "images_legeres" ) || false !== strpos( $cle, "imageslegeres" ) ) { $sales[] = $cle; }
}
sort( $sales );
echo implode( ",", $sales );' | tr -d '\r')
echo "métas de l'extension avant désinstallation : ${METAS_AVANT:-aucune}"
verifier "les trois métas de l'extension sont posées avant désinstallation" \
  $([[ "$METAS_AVANT" == "_images_legeres_crop,_images_legeres_flux,_images_legeres_meta_origine" ]] && echo 0 || echo 1)
echo "--- un .webp d'un autre outil, posé sur une sous-taille (SEC-9) ---"
# F9c : la suppression est bornée à ce dont l'extension est l'auteur PROUVÉ, c'est-à-dire
# une ligne `fait` de son inventaire. Ce témoin porte la convention de double suffixe
# d'EWWW, de WebP Express et de ShortPixel sur une sous-taille, sans aucune ligne
# d'inventaire : « Tout rendre » doit le laisser intact et le déclarer.
TIERS=$($WP eval '
$u = wp_upload_dir();
$base = trailingslashit( $u["path"] ) . "autre-outil-" . wp_generate_password( 6, false, false ) . "-300x300.jpg";
$i = new Imagick(); $i->newImage( 300, 300, new ImagickPixel( "#cc3300" ) ); $i->setImageFormat( "jpeg" );
$i->writeImage( $base ); $i->clear();
$w = new Imagick(); $w->newImage( 300, 300, new ImagickPixel( "#cc3300" ) ); $w->setImageFormat( "webp" );
$w->writeImage( $base . ".webp" ); $w->clear();
echo $base . ".webp";' | tr -d '\r')
verifier "témoin d'un autre outil écrit ($TIERS)" $([[ -f "$TIERS" ]] && echo 0 || echo 1)

TIERS_VU=$($WP eval "echo in_array( '$TIERS', ImagesLegeres_Desinstallation::lister_freres_webp_tiers(), true ) ? 'tiers' : 'REVENDIQUE';" | tr -d '\r')
verifier "le témoin d'un autre outil n'est pas revendiqué par l'inventaire (obtenu $TIERS_VU)" \
  $([[ "$TIERS_VU" == "tiers" ]] && echo 0 || echo 1)

echo "--- tout rendre, puis contrôle d'orphelins ---"
# « Tout rendre » est une action de l'écran d'administration, jouée pendant que l'extension
# est active ET que son inventaire est en place (admin_init exige le plugin chargé) : on la
# déclenche ici par `wp eval`, qui appelle le même code que le bouton, sans passer par le
# formulaire HTTP. L'ordre compte : jouée APRÈS une désinstallation de niveau 3, elle n'a
# plus d'inventaire, donc plus de preuve d'auteur, et ne peut plus rien supprimer.

# L'inventaire doit revendiquer l'original ET ses sous-tailles, pas le seul original : c'est
# ce que le README promet.
REVENDIQUES=$($WP eval '
$freres = ImagesLegeres_Desinstallation::lister_freres_webp();
$sous_tailles = 0;
foreach ( $freres as $f ) { if ( preg_match( "#-[0-9]+x[0-9]+\\.(?:jpe?g|png)\\.webp$#i", $f ) ) { $sous_tailles++; } }
echo count( $freres ) . ":" . $sous_tailles;' | tr -d '\r')
TOTAL_REVENDIQUES=${REVENDIQUES%%:*}
SOUS_TAILLES_REVENDIQUEES=${REVENDIQUES##*:}
echo "frères .webp revendiqués par l'inventaire (total:sous-tailles) : $REVENDIQUES"
verifier "l'inventaire revendique l'original ET des sous-tailles (obtenu $REVENDIQUES)" \
  $([[ "${TOTAL_REVENDIQUES:-0}" -ge 2 && "${SOUS_TAILLES_REVENDIQUEES:-0}" -ge 1 ]] && echo 0 || echo 1)

ORPHELINS_AVANT=$($WP images-legeres orphelins --format=json)
echo "orphelins (extension active, avant tout rendre, attendu non vide) : $ORPHELINS_AVANT"

$WP eval 'ImagesLegeres_Desinstallation::tout_rendre();'
verifier "tout rendre sans erreur WP-CLI" $?

if $WP db query "SHOW TABLES LIKE '%imageslegeres_fichiers'" | grep -q imageslegeres_fichiers; then
  verifier "table supprimée après tout rendre" 1
else
  verifier "table supprimée après tout rendre" 0
fi

# SEC-9 : le travail de l'autre outil survit, et lui seul.
verifier "le .webp d'un autre outil survit à « Tout rendre » (SEC-9)" $([[ -f "$TIERS" ]] && echo 0 || echo 1)

RESTANTS=$($WP eval 'echo implode( ",", ImagesLegeres_Desinstallation::lister_tous_freres_webp() );' | tr -d '\r')
echo "frères .webp restants après tout rendre : ${RESTANTS:-aucun}"
verifier "aucun frère .webp de l'extension ne survit, original comme sous-tailles (restants : ${RESTANTS:-aucun})" \
  $([[ "$RESTANTS" == "$TIERS" ]] && echo 0 || echo 1)

# Le balayage porte sur TOUTES les clés de postméta, pas sur la liste METAS : c'est le seul
# moyen d'attraper une clé que l'extension écrit mais que METAS a oubliée (réserve « Pour
# code-quality » de l'audit du lot F4+F5). Pas de `LIKE '%images_legeres%'` ici : en SQL le
# « _ » est un joker d'un caractère, le motif ramasse aussi les chemins `images-legeres/`
# des métas natives de WordPress et fait croire à un reste.
METAS_APRES=$($WP eval '
global $wpdb;
$sales = array();
foreach ( (array) $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}" ) as $cle ) {
  if ( false !== strpos( $cle, "images_legeres" ) || false !== strpos( $cle, "imageslegeres" ) ) {
    $sales[] = $cle . " (" . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $cle ) ) . ")";
  }
}
sort( $sales );
echo implode( ",", $sales );' | tr -d '\r')
verifier "aucune postméta de l'extension après désinstallation (restantes : ${METAS_APRES:-aucune})" \
  $([[ -z "$METAS_APRES" ]] && echo 0 || echo 1)

# Diagnostic, pas une assertion : ce que ramène le motif naïf `LIKE '%images_legeres%'`
# appliqué aussi aux VALEURS. En SQL le « _ » est un joker d'un caractère : le motif
# attrape les chemins `images-legeres/` que WordPress garde dans ses propres métas, et
# qui n'ont rien à faire dans METAS. C'est ce que compte un audit qui conclut « 1 ligne
# restante » sans voir qu'il s'agit d'une méta native.
NAIF=$($WP eval "
global \$wpdb;
\$motif = '%images_legeres%';
\$lignes = \$wpdb->get_results( \$wpdb->prepare( \"SELECT meta_key FROM {\$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_value LIKE %s\", \$motif, \$motif ) );
\$vus = array();
foreach ( (array) \$lignes as \$l ) { \$vus[] = \$l->meta_key; }
echo count( \$vus ) . ' : ' . implode( ',', array_unique( \$vus ) );" | tr -d '\r')
echo "motif naif LIKE pourcent-images-underscore-legeres-pourcent, cle OU valeur, apres desinstallation : $NAIF"

# tout_rendre() appelle desinstaller() mais ne désactive pas le plugin : son code reste
# chargé, la commande orphelins reste donc disponible pour prouver l'état final.
ORPHELINS_TIERS=$($WP images-legeres orphelins --format=json)
echo "orphelins (après tout rendre, le témoin tiers encore en place) : $ORPHELINS_TIERS"
verifier "le témoin tiers est déclaré à l'administrateur, pas supprimé" \
  $(echo "$ORPHELINS_TIERS" | grep -q 'autre outil' && echo 0 || echo 1)

# Le témoin retiré — il appartient à l'autre outil, c'est à nous de nettoyer notre décor —
# il ne doit plus rien rester du tout.
rm -f "$TIERS" "${TIERS%.webp}"
ORPHELINS_APRES=$($WP images-legeres orphelins --format=json)
echo "orphelins (après tout rendre, décor tiers retiré, attendu []) : $ORPHELINS_APRES"
if [[ "$ORPHELINS_APRES" == "[]" ]]; then
  verifier "wp images-legeres orphelins rend une liste vide" 0
else
  verifier "wp images-legeres orphelins rend une liste vide" 1
fi

$WP plugin deactivate images-legeres

echo "--- contrôle des drop-ins ---"
if [[ -f /var/www/html/wp-content/object-cache.php || -f /var/www/html/wp-content/advanced-cache.php ]]; then
  verifier "aucun drop-in posé" 1
else
  verifier "aucun drop-in posé" 0
fi

echo "--- réactivation puis désinstallation (niveau 3) ---"
# Joué APRÈS « Tout rendre » : ce bloc prouve que `wp plugin uninstall` charge ses
# classes et retire la table sans erreur fatale (F5), sur un site déjà rendu. Le
# contrôle des postmétas, lui, a été joué plus haut, là où il y avait des métas à
# retirer.
# --skip-delete : on isole ici la désinstallation (table/options/métas, gérée par
# uninstall.php) de la suppression du dossier du plugin, qui est le fait de WordPress
# (wp-admin « Supprimer ») et hors du périmètre de notre logique.
$WP plugin activate images-legeres
$WP plugin deactivate images-legeres
$WP plugin uninstall images-legeres --skip-delete
verifier "désinstallation sans erreur WP-CLI" $?

if $WP db query "SHOW TABLES LIKE '%imageslegeres_fichiers'" | grep -q imageslegeres_fichiers; then
  verifier "table supprimée après désinstallation" 1
else
  verifier "table supprimée après désinstallation" 0
fi


echo "--- journal PHP ---"
if [[ -s /var/www/html/wp-content/debug.log ]] && grep -i "images-legeres\|imageslegeres\|ImagesLegeres" /var/www/html/wp-content/debug.log; then
  verifier "aucune ligne du journal PHP ne mentionne l'extension" 1
else
  verifier "aucune ligne du journal PHP ne mentionne l'extension" 0
fi

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
