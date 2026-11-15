#!/usr/bin/env bash
# Test de la file de lots (mission 19), lancé depuis l'HÔTE (même raison que
# test-moteur.sh : le conteneur `cli` n'a pas de délégué WebP dans son Imagick).
# wp-cli n'est pas installé dans le conteneur `wordpress` : ce script en récupère le phar
# depuis l'image `cli` et l'exécute avec le PHP du conteneur `wordpress`. Ne modifie pas
# tests/env/.
#
# Usage : IL_ENV_NAME=... IL_HTTP_PORT=... IL_DB_PORT=... bash tests/test-lots.sh
#         (tests/env/up.sh avec les mêmes variables doit déjà avoir tourné)
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"

echo "--- préparation : wp-cli dans le conteneur wordpress (délégué WebP présent) ---"
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

echo "--- préparation : réglages par défaut, file vidée, pièces jointes image préexistantes retirées (ex. placeholder WooCommerce) pour un compte net ---"
wp eval '
ImagesLegeres_Reglages::enregistrer( ImagesLegeres_Reglages::defauts() );
ImagesLegeres_File::vider();
global $wpdb;
$existantes = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = \"attachment\" AND post_mime_type LIKE \"image/%\"" );
foreach ( $existantes as $id ) {
  wp_delete_attachment( (int) $id, true );
}
echo count( $existantes ) . " pièce(s) jointe(s) préexistante(s) retirée(s)";
' | tr -d '\r'
echo

echo "--- import d'un témoin JPEG minuscule, dupliqué en 200 pièces jointes ---"
wp eval '
$chemin = "/tmp/il-temoin-lots.jpg";
$image = new Imagick();
$image->newImage( 40, 40, new ImagickPixel( "red" ) );
$image->setImageFormat( "jpeg" );
$image->writeImage( $chemin );
$image->clear();
$image->destroy();

$uploads = wp_upload_dir();
$ids = array();
for ( $i = 1; $i <= 200; $i++ ) {
  $nom = sprintf( "temoin-lots-%03d.jpg", $i );
  $destination = trailingslashit( $uploads["path"] ) . $nom;
  copy( $chemin, $destination );
  $id = wp_insert_attachment( array(
    "post_mime_type" => "image/jpeg",
    "post_title"     => $nom,
    "post_status"    => "inherit",
  ), $destination );
  $ids[] = $id;
}
echo count( $ids );
' | tr -d '\r'
echo " pièces jointes créées"

echo "--- 1. amorçage : 200 lignes a-faire, aucune autre ---"
AMORCEES=$(wp eval 'echo ImagesLegeres_File::amorcer();' | tr -d '\r')
verifier "amorçage a ajouté 200 lignes (obtenu : $AMORCEES)" $([[ "$AMORCEES" == "200" ]] && echo 0 || echo 1)

ETATS_INITIAUX=$(wp eval 'echo json_encode(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
verifier "toutes les lignes sont a-faire au départ ($ETATS_INITIAUX)" $([[ "$ETATS_INITIAUX" == '{"a-faire":200}' ]] && echo 0 || echo 1)

echo "--- 2. traitement partiel, simulation d'une coupure brutale ---"
# Traite une partie de la file en désactivant la pause (le test porte sur la reprise, pas sur
# le minutage), puis fige manuellement des lignes "en-cours" avec une prise ancienne, comme le
# laisserait un process tué au milieu d'un fichier.
wp eval '
global $wpdb;
$table = ImagesLegeres_File::nom();
for ( $i = 0; $i < 80; $i++ ) {
  $id = ImagesLegeres_File::prochaine();
  if ( ! $id ) break;
  ImagesLegeres_File::marquer_en_cours( $id );
  $resultats = ImagesLegeres_Moteur::traiter_attachment( $id );
  $etat = "fait";
  foreach ( $resultats as $r ) { if ( "erreur" === $r["etat"] ) $etat = "erreur"; }
  ImagesLegeres_File::marquer_resultat( $id, $etat, "" );
}
// Coupure brutale : 5 lignes restent "en-cours" avec une prise très ancienne (process tué).
$bloquees = $wpdb->get_col( "SELECT attachment_id FROM {$table} WHERE etat = \"a-faire\" ORDER BY id ASC LIMIT 5" );
foreach ( $bloquees as $id ) {
  $wpdb->update( $table, array( "etat" => "en-cours", "prise_le" => "2000-01-01 00:00:00" ), array( "attachment_id" => $id ) );
}
echo count( $bloquees );
' >/dev/null

AVANT_REPRISE=$(wp eval 'echo json_encode(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
echo "état avant reprise : $AVANT_REPRISE"
verifier "80 lignes traitées, 5 figées en-cours avant reprise" $(echo "$AVANT_REPRISE" | grep -q '"fait":80' && echo "$AVANT_REPRISE" | grep -q '"en-cours":5' && echo 0 || echo 1)

wp eval '
global $wpdb;
$instantane = $wpdb->get_results( "SELECT attachment_id, maj FROM " . ImagesLegeres_File::nom() . " WHERE etat = \"fait\"", ARRAY_A );
update_option( "_test_instantane_fait", wp_json_encode( $instantane ) );
echo count( $instantane );
' | tr -d '\r'
echo " lignes 'fait' mises en instantané avant reprise"

echo "--- 3. reprise : relance jusqu'à épuisement de la file, sans sauter ni retraiter ---"
# Plage horaire ouverte en continu (début = fin) et seuil de charge réglé haut (réglage de ce
# WordPress jetable, pas le défaut du code) : ces tests portent sur la reprise et la plage
# horaire, pas sur la charge réelle de la machine de l'usine au moment du test (garde-fou
# vérifié isolément à l'étape 4bis, seuil réglé très bas).
wp eval '$r = ImagesLegeres_Reglages::tout(); $r["plage_horaire_debut"] = "00:00"; $r["plage_horaire_fin"] = "00:00"; $r["charge_max_serveur"] = 1000.0; ImagesLegeres_Reglages::enregistrer($r);' >/dev/null
RESULTAT_RUN=$(wp images-legeres run --limite=0 --format=json)
echo "$RESULTAT_RUN"

APRES_REPRISE=$(wp eval 'echo json_encode(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
echo "état après reprise : $APRES_REPRISE"
verifier "plus aucune ligne a-faire ni en-cours après reprise" $(echo "$APRES_REPRISE" | grep -qE '"a-faire"|"en-cours"' && echo 1 || echo 0)

TOTAL_APRES=$(wp eval 'echo array_sum(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
verifier "200 lignes au total, aucune perdue (obtenu : $TOTAL_APRES)" $([[ "$TOTAL_APRES" == "200" ]] && echo 0 || echo 1)

# Les lignes déjà "fait" avant la coupure n'ont pas été retraitées : leur date de mise à jour
# (colonne maj) est inchangée par la reprise, comparée à l'instantané pris juste avant.
PAS_RETRAITE=$(wp eval '
global $wpdb;
$instantane = json_decode( get_option( "_test_instantane_fait" ), true );
$incoherences = 0;
foreach ( $instantane as $ligne ) {
  $maj_actuel = $wpdb->get_var( $wpdb->prepare( "SELECT maj FROM " . ImagesLegeres_File::nom() . " WHERE attachment_id = %d", $ligne["attachment_id"] ) );
  if ( $maj_actuel !== $ligne["maj"] ) {
    $incoherences++;
  }
}
echo count( $instantane ) . "/" . $incoherences;
' | tr -d '\r')
echo "lignes déjà 'fait' avant coupure / incohérences après reprise : $PAS_RETRAITE"
verifier "les lignes déjà 'fait' avant la coupure n'ont pas été retraitées" $([[ "$PAS_RETRAITE" == "80/0" ]] && echo 0 || echo 1)

echo "--- 4. la file refuse de tourner hors plage horaire ---"
wp eval '
$maintenant = current_time("H:i");
$parties = explode(":", $maintenant);
$debut = sprintf("%02d:%02d", (((int)$parties[0]) + 1) % 24, $parties[1]);
$fin   = sprintf("%02d:%02d", (((int)$parties[0]) + 2) % 24, $parties[1]);
$reglages = ImagesLegeres_Reglages::tout();
$reglages["plage_horaire_debut"] = $debut;
$reglages["plage_horaire_fin"]   = $fin;
ImagesLegeres_Reglages::enregistrer($reglages);
echo "$debut-$fin";
' | tr -d '\r'
echo " (plage horaire posée en dehors de maintenant)"

# Repeuple 3 pièces jointes toutes neuves pour que la file ait quelque chose à refuser de traiter.
wp eval '
$uploads = wp_upload_dir();
for ($i = 201; $i <= 203; $i++) {
  $nom = sprintf("temoin-lots-%03d.jpg", $i);
  $destination = trailingslashit($uploads["path"]) . $nom;
  copy("/tmp/il-temoin-lots.jpg", $destination);
  wp_insert_attachment(array("post_mime_type" => "image/jpeg", "post_title" => $nom, "post_status" => "inherit"), $destination);
}
' >/dev/null

AVANT_HORS_PLAGE=$(wp eval 'echo array_sum(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
wp images-legeres run --limite=0 --format=json 2>/tmp/il-run-stderr.log
cat /tmp/il-run-stderr.log
APRES_HORS_PLAGE=$(wp eval 'echo array_sum(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')

verifier "message de refus hors plage horaire" $(grep -q "hors plage horaire" /tmp/il-run-stderr.log && echo 0 || echo 1)
# L'amorçage ajoute les 3 nouvelles pièces jointes (203 lignes au total) mais aucune n'est traitée.
verifier "aucune ligne traitée hors plage horaire (total inchangé après amorçage : $APRES_HORS_PLAGE)" $([[ "$APRES_HORS_PLAGE" == "203" ]] && echo 0 || echo 1)
ETATS_HORS_PLAGE=$(wp eval 'echo json_encode(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
verifier "les 3 nouvelles lignes restent a-faire (hors plage horaire) : $ETATS_HORS_PLAGE" $(echo "$ETATS_HORS_PLAGE" | grep -q '"a-faire":3' && echo 0 || echo 1)

echo "--- forcer ignore la plage horaire ---"
wp images-legeres run --limite=3 --forcer --format=json
ETATS_FORCE=$(wp eval 'echo json_encode(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
verifier "--forcer traite malgré la plage horaire ($ETATS_FORCE)" $(echo "$ETATS_FORCE" | grep -qE '"a-faire"' && echo 1 || echo 0)

echo "--- 4bis. le garde-fou de charge bloque bien quand la charge dépasse le seuil ---"
# Plage horaire remise continue (l'étape 4 l'avait posée hors de "maintenant") pour isoler le
# garde-fou de charge de celui de la plage horaire. Seuil réglé très bas (réglage de ce
# WordPress jetable, le défaut du code reste 4.0) : le garde-fou doit bloquer quel que soit
# l'état réel de la machine au moment du test.
wp eval '
$uploads = wp_upload_dir();
for ($i = 301; $i <= 302; $i++) {
  $nom = sprintf("temoin-lots-charge-%03d.jpg", $i);
  $destination = trailingslashit($uploads["path"]) . $nom;
  copy("/tmp/il-temoin-lots.jpg", $destination);
  wp_insert_attachment(array("post_mime_type" => "image/jpeg", "post_title" => $nom, "post_status" => "inherit"), $destination);
}
$r = ImagesLegeres_Reglages::tout();
$r["plage_horaire_debut"] = "00:00";
$r["plage_horaire_fin"]   = "00:00";
$r["charge_max_serveur"]  = 0.0001;
ImagesLegeres_Reglages::enregistrer($r);
' >/dev/null

RESULTAT_CHARGE=$(wp images-legeres run --limite=0 --format=json)
echo "$RESULTAT_CHARGE"

verifier "message d'arrêt anticipé pour charge trop élevée" $(echo "$RESULTAT_CHARGE" | grep -q "arrêt anticipé" && echo "$RESULTAT_CHARGE" | grep -q "charge serveur trop élevée" && echo 0 || echo 1)
verifier "aucune ligne traitée, seuil de charge dépassé" $(echo "$RESULTAT_CHARGE" | grep -q '"cle":"traites","valeur":"0"' && echo 0 || echo 1)
ETATS_CHARGE=$(wp eval 'echo json_encode(ImagesLegeres_File::compter_par_etat());' | tr -d '\r')
verifier "les 2 nouvelles lignes restent a-faire (seuil de charge dépassé) : $ETATS_CHARGE" $(echo "$ETATS_CHARGE" | grep -q '"a-faire":2' && echo 0 || echo 1)

# Seuil remis haut pour le reste du script (sections suivantes).
wp eval '$r = ImagesLegeres_Reglages::tout(); $r["charge_max_serveur"] = 1000.0; ImagesLegeres_Reglages::enregistrer($r);' >/dev/null

echo "--- 5. point d'accroche flux : se déclenche seulement si un réglage de flux change ---"
RESULTAT_FLUX=$(wp eval '
$compteur = 0;
add_action("images_legeres_flux_reglages_changes", function() use (&$compteur) { $compteur++; });

$avant = ImagesLegeres_Reglages::defauts();
$apres_sans_changement = $avant;
$declenche_sans = ImagesLegeres_Lot::signaler_si_reglages_flux_changes($avant, $apres_sans_changement);

$apres_avec_changement = $avant;
$apres_avec_changement["flux_google_actif"] = true;
$declenche_avec = ImagesLegeres_Lot::signaler_si_reglages_flux_changes($avant, $apres_avec_changement);

echo json_encode(array("compteur" => $compteur, "sans" => $declenche_sans, "avec" => $declenche_avec));
' | tr -d '\r')
echo "$RESULTAT_FLUX"
verifier "accroche flux : silencieuse sans changement, déclenchée avec changement, une seule fois" $([[ "$RESULTAT_FLUX" == '{"compteur":1,"sans":false,"avec":true}' ]] && echo 0 || echo 1)

echo "--- 6. aucun drop-in, désactivation annule les planifications Action Scheduler ---"
wp eval 'ImagesLegeres_Lot::planifier_prochaine(3600);' >/dev/null
PLANIFIEE_AVANT=$(wp eval 'echo as_has_scheduled_action(ImagesLegeres_Lot::HOOK, array(), ImagesLegeres_Lot::GROUPE_AS) ? "1" : "0";' | tr -d '\r')
wp plugin deactivate images-legeres >/dev/null
wp plugin activate images-legeres >/dev/null
PLANIFIEE_APRES=$(wp eval 'echo as_has_scheduled_action(ImagesLegeres_Lot::HOOK, array(), ImagesLegeres_Lot::GROUPE_AS) ? "1" : "0";' | tr -d '\r')
verifier "une action planifiée (avant : $PLANIFIEE_AVANT) est annulée à la désactivation (après : $PLANIFIEE_APRES)" $([[ "$PLANIFIEE_AVANT" == "1" && "$PLANIFIEE_APRES" == "0" ]] && echo 0 || echo 1)

if [[ -f /var/www/html/wp-content/object-cache.php ]] || [[ -f /var/www/html/wp-content/advanced-cache.php ]]; then
  verifier "aucun drop-in posé" 1
else
  verifier "aucun drop-in posé" 0
fi

echo "--- 7. changer un réglage de flux remet l'image produit en file ; la file régénère le fichier et supprime l'ancien ---"
# Plage horaire continue à nouveau (étape 4 l'avait posée hors de "maintenant") : ce test porte
# sur la régénération du flux, pas sur la plage horaire.
wp eval '$r = ImagesLegeres_Reglages::tout(); $r["plage_horaire_debut"] = "00:00"; $r["plage_horaire_fin"] = "00:00"; ImagesLegeres_Reglages::enregistrer($r);' >/dev/null

INFOS_PRODUIT=$(wp eval '
$produit_id = wp_insert_post( array( "post_type" => "product", "post_title" => "Bougie témoin lots", "post_status" => "publish" ) );

$uploads     = wp_upload_dir();
$nom         = "temoin-lots-produit.jpg";
$destination = trailingslashit( $uploads["path"] ) . $nom;
$image       = new Imagick();
$image->newImage( 900, 900, new ImagickPixel( "blue" ) );
$image->setImageFormat( "jpeg" );
$image->writeImage( $destination );
$image->clear();
$image->destroy();

$image_id = wp_insert_attachment( array(
  "post_mime_type" => "image/jpeg",
  "post_title"      => $nom,
  "post_status"     => "inherit",
  "post_parent"     => $produit_id,
), $destination );
update_post_meta( $produit_id, "_thumbnail_id", $image_id );

echo "$produit_id $image_id";
' | tr -d '\r')
read -r PRODUIT_ID IMAGE_PRODUIT_ID <<< "$INFOS_PRODUIT"
echo "produit $PRODUIT_ID, image $IMAGE_PRODUIT_ID"

wp eval '
$reglages = ImagesLegeres_Reglages::tout();
$reglages["image_flux_active"] = true;
$reglages["flux_google_actif"] = true;
$reglages["dimension_flux"]    = 600;
ImagesLegeres_Reglages::enregistrer( $reglages );
' >/dev/null

# Isole la ligne de l'image produit dans la file (étape propre à ce test, sans effet sur la
# régénération testée) : le reste de la file porte les 203 témoins des étapes précédentes, qui
# passeraient devant elle (prochaine() prend la plus ancienne) et ralentiraient ce test sans
# rien lui apporter.
wp eval "ImagesLegeres_File::amorcer(); global \$wpdb; \$wpdb->query( \"UPDATE \" . ImagesLegeres_File::nom() . \" SET etat = 'ignore' WHERE attachment_id != ${IMAGE_PRODUIT_ID} AND etat IN ('a-faire', 'en-cours')\" );" >/dev/null

# --forcer : ce test porte sur la régénération par la file, pas sur le garde-fou de charge
# (vérifié isolément par les tests de mission 19) ; l'image isolée ci-dessus est la seule
# ligne a-faire, donc une seule image est traitée quel que soit l'état réel de la machine.
wp images-legeres run --limite=0 --forcer --format=json >/dev/null

# Chemins rendus par flux_existant() : côté conteneur, pas côté hôte (ce script tourne sur
# l'hôte, les tests d'existence passent donc par wp eval, pas par le -f de bash).
fichier_existe_conteneur() {
  [[ -n "$1" ]] && [[ "$(wp eval "echo file_exists('$1') ? '1' : '0';" | tr -d '\r')" == "1" ]]
}

FICHIER_AVANT=$(wp eval "\$f = ImagesLegeres_Flux::flux_existant(${IMAGE_PRODUIT_ID}); echo \$f ? \$f['chemin'] : '';" | tr -d '\r')
verifier "fichier de flux produit avant changement de réglage ($FICHIER_AVANT)" $(fichier_existe_conteneur "$FICHIER_AVANT" && echo 0 || echo 1)

ETAT_AVANT_CHANGEMENT=$(wp eval "\$l = ImagesLegeres_File::ligne(${IMAGE_PRODUIT_ID}); echo \$l['etat'];" | tr -d '\r')
verifier "l'image produit est 'fait' avant le changement de réglage ($ETAT_AVANT_CHANGEMENT)" $([[ "$ETAT_AVANT_CHANGEMENT" == "fait" ]] && echo 0 || echo 1)

wp eval '
$avant = ImagesLegeres_Reglages::tout();
$apres = $avant;
$apres["dimension_flux"] = 500;
ImagesLegeres_Reglages::enregistrer( $apres );
ImagesLegeres_Lot::signaler_si_reglages_flux_changes( $avant, $apres );
' >/dev/null

ETAT_APRES_CHANGEMENT=$(wp eval "\$l = ImagesLegeres_File::ligne(${IMAGE_PRODUIT_ID}); echo \$l['etat'];" | tr -d '\r')
verifier "le réglage de flux changé remet l'image produit en file (a-faire, obtenu $ETAT_APRES_CHANGEMENT)" $([[ "$ETAT_APRES_CHANGEMENT" == "a-faire" ]] && echo 0 || echo 1)

wp images-legeres run --limite=0 --forcer --format=json >/dev/null

FICHIER_APRES=$(wp eval "\$f = ImagesLegeres_Flux::flux_existant(${IMAGE_PRODUIT_ID}); echo \$f ? \$f['chemin'] : '';" | tr -d '\r')
verifier "la file a régénéré un nouveau fichier de flux ($FICHIER_AVANT -> $FICHIER_APRES)" $([[ -n "$FICHIER_APRES" && "$FICHIER_APRES" != "$FICHIER_AVANT" ]] && echo 0 || echo 1)
verifier "le nouveau fichier de flux existe sur disque ($FICHIER_APRES)" $(fichier_existe_conteneur "$FICHIER_APRES" && echo 0 || echo 1)
verifier "l'ancien fichier de flux a été supprimé par la régénération ($FICHIER_AVANT)" $(fichier_existe_conteneur "$FICHIER_AVANT" && echo 1 || echo 0)

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
