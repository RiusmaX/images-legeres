#!/usr/bin/env bash
# Traitement automatique des nouvelles images (0.2.3), lancé depuis l'HÔTE. Les images ne sont
# jamais traitées par un appel à l'extension : seul wp-cron.php est sollicité, comme par un
# visiteur, et Action Scheduler fait le reste.
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides).
# Usage : IL_HTTP_PORT=8099 bash tests/test-auto.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"

BASE="http://localhost:${IL_HTTP_PORT:-8081}"

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
php_wp() { wp eval "$1" | tr -d '\r'; }

ECHECS=0
verifier() {
  if [[ "$2" -eq 0 ]]; then echo "OK   - $1"; else echo "FAIL - $1"; ECHECS=$((ECHECS + 1)); fi
}
egal() { verifier "$1 (attendu : $2, obtenu : $3)" $([[ "$2" == "$3" ]] && echo 0 || echo 1); }

etat() { php_wp "\$l = ImagesLegeres_File::ligne($1); echo \$l ? \$l['etat'] : 'absente';"; }
harmonisees() { php_wp "echo count( ImagesLegeres_Harmonisees::par_attachment($1) );"; }
# Harmonisées carrées sur le disque, chacune avec son .webp.
carrees_webp() {
  php_wp "\$b = trailingslashit( wp_get_upload_dir()['basedir'] ); \$l = ImagesLegeres_Harmonisees::par_attachment($1); \$ok = count(\$l) > 0; foreach ( \$l as \$x ) { \$f = \$b . \$x['chemin_harmonise']; \$m = getimagesize(\$f); \$ok = \$ok && \$m[0] === \$m[1] && file_exists(\$f . '.webp'); } echo \$ok ? 'oui' : 'non';"
}
webp_natifs() {
  php_wp "\$f = ImagesLegeres_Moteur::fichiers_de_attachment($1); \$ok = count(\$f) > 1; foreach ( \$f as \$c ) { \$ok = \$ok && file_exists(\$c . '.webp'); } echo \$ok ? 'oui' : 'non';"
}
en_attente() {
  php_wp "echo count( as_get_scheduled_actions( array( 'hook' => ImagesLegeres_Lot::HOOK_AUTO, 'status' => 'pending', 'per_page' => 50 ), 'ids' ) );"
}
# Sollicite WP-Cron jusqu'à ce que toutes les images données soient « fait » (6 min au plus).
attendre() {
  local debut=$SECONDS id restant
  while (( SECONDS - debut < 360 )); do
    curl -s -m 120 "$BASE/wp-cron.php?doing_wp_cron" >/dev/null
    restant=0
    for id in "$@"; do [[ "$(etat "$id")" == "fait" ]] || restant=1; done
    [[ $restant -eq 0 ]] && { echo "traité en $((SECONDS - debut)) s"; return 0; }
    sleep 5
  done
  echo "toujours pas traité après 360 s"
  for id in "$@"; do php_wp "echo json_encode( ImagesLegeres_File::ligne($id) );"; echo; done
  return 1
}
# Envoi réel (sous-tailles et métadonnées créées par WordPress), photo non carrée.
envoyer() {
  docker compose -f compose.yml exec -T wordpress php -r '$i = new Imagick(); $i->newPseudoImage(1400, 900, "gradient:orange-navy"); $i->setImageFormat("jpeg"); $i->writeImage("/tmp/'"$1"'.jpg");'
  wp media import "/tmp/$1.jpg" --porcelain | tr -d '\r'
}

echo "--- préparation : lot à l'arrêt, plage horaire hors de maintenant, harmonisation active ---"
php_wp '
$h = (int) current_time("H");
$r = ImagesLegeres_Reglages::defauts();
$r["plage_horaire_debut"] = sprintf("%02d:00", ($h + 3) % 24);
$r["plage_horaire_fin"]   = sprintf("%02d:00", ($h + 4) % 24);
$r["harmonisation_produit_active"] = true;
$r["charge_max_serveur"] = 1000.0;
$r["pause_entre_images_secondes"] = 0; // sinon une image par passage de WP-Cron
ImagesLegeres_Reglages::enregistrer($r);
delete_option(ImagesLegeres_Lot::OPTION_ETAT);
ImagesLegeres_File::vider();
'
egal "lot à l'arrêt au départ" "arret" "$(php_wp 'echo ImagesLegeres_Lot::etat();')"
egal "hors plage horaire" "non" "$(php_wp 'echo ImagesLegeres_Lot::dans_plage_horaire() ? "oui" : "non";')"
# Une ligne du stock (hors voie automatique) doit rester pour le lot de nuit.
STOCK=$(envoyer stock)
php_wp "global \$wpdb; \$wpdb->update( ImagesLegeres_File::nom(), array( 'motif' => '' ), array( 'attachment_id' => $STOCK ) );"

echo "--- 1. envoi d'une image : traitée sans appel manuel, non harmonisée ---"
LIBRE=$(envoyer libre)
egal "en file après l'envoi, pas traitée pendant l'envoi" "a-faire" "$(etat "$LIBRE")"
egal "une seule action en attente" "1" "$(en_attente)"
attendre "$LIBRE"; verifier "image envoyée traitée" $?
egal "WebP de l'original et des sous-tailles" "oui" "$(webp_natifs "$LIBRE")"
egal "image non liée à un produit : pas harmonisée" "0" "$(harmonisees "$LIBRE")"
egal "la ligne du stock reste au lot de nuit" "a-faire" "$(etat "$STOCK")"
egal "l'état du lot n'a pas bougé" "arret" "$(php_wp 'echo ImagesLegeres_Lot::etat();')"

# La suite 1 a prouvé le rythme réel (délai d'une minute, passage d'Action Scheduler à la
# minute). Pour la suite, les deux attentes sont raccourcies, sinon elle dure plus de 10 min.
docker compose -f compose.yml exec -T wordpress sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/il-test-rythme.php' <<'PHP'
<?php
add_filter( 'images_legeres_auto_delai_secondes', '__return_zero' );
add_filter( 'cron_schedules', static function ( $s ) { $s['il_test'] = array( 'interval' => 5, 'display' => '5 s' ); return $s; } );
add_filter( 'action_scheduler_run_schedule', static function () { return 'il_test'; } );
PHP
php_wp 'wp_unschedule_hook("action_scheduler_run_queue");'

echo "--- 2. image principale, galerie, variation (création) ---"
PRINCIPALE=$(envoyer principale); GALERIE=$(envoyer galerie); VARIATION=$(envoyer variation)
attendre "$PRINCIPALE" "$GALERIE" "$VARIATION"; verifier "trois envois traités" $?
egal "avant la pose : rien d'harmonisé" "0 0 0" "$(harmonisees "$PRINCIPALE") $(harmonisees "$GALERIE") $(harmonisees "$VARIATION")"
PRODUIT=$(php_wp "\$p = new WC_Product_Variable(); \$p->set_name('Témoin auto'); \$p->set_image_id($PRINCIPALE); echo \$p->save();")
egal "image principale remise en file" "a-faire" "$(etat "$PRINCIPALE")"
attendre "$PRINCIPALE"; verifier "image principale traitée" $?
egal "image principale : carrée + WebP" "oui" "$(carrees_webp "$PRINCIPALE")"

echo "--- modification : galerie ajoutée, puis variation ---"
php_wp "\$p = wc_get_product($PRODUIT); \$p->set_gallery_image_ids( array( $GALERIE ) ); \$p->save();"
php_wp "\$v = new WC_Product_Variation(); \$v->set_parent_id($PRODUIT); \$v->set_image_id($VARIATION); \$v->save();"
attendre "$GALERIE" "$VARIATION"; verifier "galerie et variation traitées" $?
egal "galerie : carrée + WebP" "oui" "$(carrees_webp "$GALERIE")"
egal "variation : carrée + WebP" "oui" "$(carrees_webp "$VARIATION")"
egal "image libre toujours pas harmonisée" "0" "$(harmonisees "$LIBRE")"
egal "originaux intacts" "1400x900" "$(php_wp "\$m = getimagesize( get_attached_file($PRINCIPALE) ); echo \$m[0] . 'x' . \$m[1];")"

echo "--- 3. image déjà harmonisée reposée : ni remise en file ni réécrite ---"
php_wp "delete_post_meta($PRODUIT, '_thumbnail_id'); add_post_meta($PRODUIT, '_thumbnail_id', $PRINCIPALE);"
egal "reste « fait »" "fait" "$(etat "$PRINCIPALE")"

echo "--- 4. image posée sur un produit avant d'être traitée (envoi puis pose immédiate) ---"
DIRECTE=$(envoyer directe)
php_wp "\$p = wc_get_product($PRODUIT); \$p->set_gallery_image_ids( array( $GALERIE, $DIRECTE ) ); \$p->save();"
egal "une seule action en attente" "1" "$(en_attente)"
attendre "$DIRECTE"; verifier "traitée" $?
egal "carrée + WebP" "oui" "$(carrees_webp "$DIRECTE")"

echo "--- 5. charge trop élevée : replanifié, pas traité, pas de double planification ---"
php_wp '$r = ImagesLegeres_Reglages::tout(); $r["charge_max_serveur"] = 0.0001; ImagesLegeres_Reglages::enregistrer($r);'
CHARGE=$(envoyer charge)
php_wp "for ( \$i = 0; \$i < 3; \$i++ ) { ImagesLegeres_Lot::mettre_en_file_auto($CHARGE, ImagesLegeres_File::MOTIF_SOUS_TAILLES); }"
egal "une seule action en attente après mises en file répétées" "1" "$(en_attente)"
RETARD=$(php_wp 'ImagesLegeres_Lot::traiter_nouvelles(); echo as_next_scheduled_action( ImagesLegeres_Lot::HOOK_AUTO ) - time();')
verifier "replanifié à environ 5 min (obtenu : ${RETARD} s)" $([[ "$RETARD" -gt 240 && "$RETARD" -le 300 ]] && echo 0 || echo 1)
egal "toujours à faire" "a-faire" "$(etat "$CHARGE")"
egal "toujours une seule action en attente" "1" "$(en_attente)"

echo "--- 6. coupures répétées : abandon au deuxième coup, la file n'est pas bloquée ---"
couper() { php_wp "global \$wpdb; ImagesLegeres_File::marquer_en_cours($CHARGE); \$wpdb->update( ImagesLegeres_File::nom(), array( 'prise_le' => '2000-01-01 00:00:00' ), array( 'attachment_id' => $CHARGE ) ); ImagesLegeres_File::reprendre_bloquees();"; }
couper; egal "première coupure : reprise" "a-faire" "$(etat "$CHARGE")"
couper; egal "deuxième coupure : erreur" "erreur" "$(etat "$CHARGE")"

echo "--- 7. réglages de flux : une ligne automatique en attente le reste ---"
php_wp "\$p = ImagesLegeres_File::remettre_a_faire($PRINCIPALE, ImagesLegeres_File::MOTIF_PRODUIT); ImagesLegeres_File::remettre_images_produit_a_faire();"
egal "motif conservé" "posée sur un produit" "$(php_wp "echo ImagesLegeres_File::ligne($PRINCIPALE)['motif'];")"
egal "les autres images produit restent hors voie automatique" "réglages de flux modifiés" "$(php_wp "echo ImagesLegeres_File::ligne($GALERIE)['motif'];")"

echo "--- 8. désactivation : plus d'action en attente ---"
wp plugin deactivate images-legeres >/dev/null
wp plugin activate images-legeres >/dev/null
egal "aucune action en attente" "0" "$(en_attente)"

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then echo "TOUS LES TESTS PASSENT"; else echo "$ECHECS TEST(S) EN ÉCHEC"; exit 1; fi
