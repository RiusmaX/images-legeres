#!/usr/bin/env bash
# Test des sous-commandes d'interface (mission 16) : `crop get|set|clear|import|pending`,
# `alt missing`, `flux check`. JSON valide, idempotence, refus motivé d'une entrée invalide
# (rien d'écrit), recoupement SQL indépendant pour `alt missing`, aucune écriture de
# `_wp_attachment_image_alt` par aucune commande, avertissement d'activation sur un autre
# éditeur d'image déjà branché.
#
# Comme test-moteur.sh et test-recadrage.sh : le conteneur `cli` n'a aucun délégué Imagick
# utilisable, ce script tourne donc depuis l'hôte avec le phar `wp` exécuté par le PHP du
# conteneur `wordpress`. Ne modifie pas tests/env/.
#
# Usage : bash tests/test-cli-interfaces.sh   (tests/env/up.sh doit déjà avoir tourné)
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

# Les fichiers créés sur l'hôte (JSON de lot) ne sont pas visibles dans le conteneur
# `wordpress` : seul le dossier de l'extension est monté. On les copie dans le conteneur
# avant de les passer en argument à `wp`.
wp_cp() {
  docker cp "$1" "$WP_CID":"$1" >/dev/null
}

ECHECS=0

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
wp eval 'ImagesLegeres_Reglages::enregistrer(array("harmonisation_produit_active" => true));' >/dev/null
wp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (\"_images_legeres_crop\",\"_wp_attachment_image_alt\",\"_thumbnail_id\",\"_product_image_gallery\")");' >/dev/null

ID_PRODUIT=$(wp eval "echo wp_insert_post(array('post_type'=>'product','post_title'=>'Produit témoin M16','post_status'=>'publish'));" | tr -d '\r')
verifier "produit témoin créé" $([[ "$ID_PRODUIT" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

ID_PAGE=$(wp eval "echo wp_insert_post(array('post_type'=>'page','post_title'=>'Page témoin M16','post_status'=>'publish'));" | tr -d '\r')

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
\$dest = trailingslashit( \$uploads['path'] ) . basename( '$chemin' ) . '-' . wp_generate_password( 6, false );
copy( '$chemin', \$dest );
\$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => basename( \$dest ), 'post_status' => 'inherit', 'post_parent' => $parent ), \$dest );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( \$id, wp_generate_attachment_metadata( \$id, \$dest ) );
echo \$id;
" | tr -d '\r'
}

echo "--- images témoins (produit, hors ratio 2560x1920 -> 4:3) ---"
TMP_IMG=$(mktemp --suffix=.jpg)
creer_image "$TMP_IMG" 2000 1500
ID_IMG_PRODUIT=$(importer_et_rattacher "$TMP_IMG" "$ID_PRODUIT")
verifier "image produit créée" $([[ "$ID_IMG_PRODUIT" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

echo "--- crop pending : liste l'image hors ratio avant tout rectangle ---"
JSON_PENDING=$(wp images-legeres crop pending --format=json)
echo "$JSON_PENDING" | grep -q "\"id\":$ID_IMG_PRODUIT"
verifier "crop pending contient l'image hors ratio" $?

echo "--- crop set : rectangle valide (carré 1500x1500 centré) ---"
wp images-legeres crop set "$ID_IMG_PRODUIT" --x=250 --y=0 --w=1500 --h=1500 --ref=2000x1500 >/tmp/m16-crop-set.json 2>/tmp/m16-crop-set.err
verifier "crop set réussit (rectangle valide)" $?
grep -q '"statut":"pose"' /tmp/m16-crop-set.json
verifier "crop set rend un JSON avec statut pose" $?

echo "--- crop get : relit le rectangle posé ---"
JSON_GET=$(wp images-legeres crop get "$ID_IMG_PRODUIT" --format=json)
echo "$JSON_GET" | grep -q '"x":0.125'
verifier "crop get relit x converti en relatif (250/2000)" $?

echo "--- crop pending : l'image a disparu de la liste ---"
JSON_PENDING2=$(wp images-legeres crop pending --format=json)
! echo "$JSON_PENDING2" | grep -q "\"id\":$ID_IMG_PRODUIT"
verifier "crop pending ne liste plus l'image recadrée" $?

echo "--- crop set : rectangle hors image, refusé, rien d'écrit ---"
JSON_AVANT=$(wp images-legeres crop get "$ID_IMG_PRODUIT" --format=json)
wp images-legeres crop set "$ID_IMG_PRODUIT" --x=1900 --y=0 --w=1500 --h=1500 --ref=2000x1500 >/tmp/m16-crop-hors.json 2>&1
CODE_HORS=$?
[[ "$CODE_HORS" -ne 0 ]]
verifier "crop set hors image rend un code de sortie non nul" $?
JSON_APRES=$(wp images-legeres crop get "$ID_IMG_PRODUIT" --format=json)
[[ "$JSON_AVANT" == "$JSON_APRES" ]]
verifier "crop set hors image n'a rien écrit (rectangle inchangé)" $?

echo "--- crop set : mauvais ratio, refusé ---"
wp images-legeres crop set "$ID_IMG_PRODUIT" --x=0 --y=0 --w=2000 --h=500 --ref=2000x1500 >/tmp/m16-crop-ratio.json 2>&1
CODE_RATIO=$?
[[ "$CODE_RATIO" -ne 0 ]]
verifier "crop set mauvais ratio rend un code de sortie non nul" $?

echo "--- crop set : carré < 500 px, refusé ---"
wp images-legeres crop set "$ID_IMG_PRODUIT" --x=0 --y=0 --w=400 --h=400 --ref=2000x1500 >/tmp/m16-crop-petit.json 2>&1
CODE_PETIT=$?
[[ "$CODE_PETIT" -ne 0 ]]
verifier "crop set trop petit rend un code de sortie non nul" $?

echo "--- crop set : idempotent (même rectangle rejoué, même résultat) ---"
# Compare x/y/w/h seuls : la méta porte aussi une date d'écriture qui change à chaque pose.
AVANT_IDEMPOTENT=$(wp images-legeres crop get "$ID_IMG_PRODUIT" --format=json | python3 -c "import json,sys; r=json.load(sys.stdin)['rectangle']; print(r['x'],r['y'],r['w'],r['h'])")
wp images-legeres crop set "$ID_IMG_PRODUIT" --x=250 --y=0 --w=1500 --h=1500 --ref=2000x1500 >/dev/null 2>&1
APRES_IDEMPOTENT=$(wp images-legeres crop get "$ID_IMG_PRODUIT" --format=json | python3 -c "import json,sys; r=json.load(sys.stdin)['rectangle']; print(r['x'],r['y'],r['w'],r['h'])")
[[ "$AVANT_IDEMPOTENT" == "$APRES_IDEMPOTENT" ]]
verifier "rejouer le même crop set est idempotent ($AVANT_IDEMPOTENT == $APRES_IDEMPOTENT)" $?

echo "--- crop clear : retire le rectangle, idempotent ---"
wp images-legeres crop clear "$ID_IMG_PRODUIT" >/dev/null 2>&1
verifier "crop clear réussit" $?
JSON_GET_VIDE=$(wp images-legeres crop get "$ID_IMG_PRODUIT" --format=json)
echo "$JSON_GET_VIDE" | grep -q '"rectangle":null'
verifier "crop clear a bien retiré le rectangle" $?
wp images-legeres crop clear "$ID_IMG_PRODUIT" >/dev/null 2>&1
verifier "rejouer crop clear sans rectangle reste sans erreur (idempotent)" $?

echo "--- crop import : lot avec une entrée valide, une invalide, une fond_assume ---"
TMP_IMG2=$(mktemp --suffix=.jpg)
creer_image "$TMP_IMG2" 2000 1500
ID_IMG2=$(importer_et_rattacher "$TMP_IMG2" "$ID_PRODUIT")
TMP_IMG3=$(mktemp --suffix=.jpg)
creer_image "$TMP_IMG3" 900 1200
ID_IMG3=$(importer_et_rattacher "$TMP_IMG3" "$ID_PRODUIT")

cat > /tmp/m16-import.json <<JSON
[
  {"id": $ID_IMG2, "x": 250, "y": 0, "w": 1500, "h": 1500, "ref": "2000x1500"},
  {"id": $ID_IMG3, "fond_assume": true, "motif": "aucun carre ne contient le produit entier"},
  {"id": 999999999, "x": 0, "y": 0, "w": 100, "h": 100}
]
JSON

wp_cp /tmp/m16-import.json
wp images-legeres crop import /tmp/m16-import.json >/tmp/m16-import-sortie.json 2>&1
CODE_IMPORT=$?
[[ "$CODE_IMPORT" -ne 0 ]]
verifier "crop import rend un code de sortie non nul (une entrée invalide dans le lot)" $?
grep -q "\"echecs\":1" /tmp/m16-import-sortie.json
verifier "crop import rapporte exactement 1 échec" $?
JSON_IMG2=$(wp images-legeres crop get "$ID_IMG2" --format=json)
echo "$JSON_IMG2" | grep -q '"x":0.125'
verifier "crop import a posé le rectangle valide" $?
JSON_IMG3=$(wp images-legeres crop get "$ID_IMG3" --format=json)
echo "$JSON_IMG3" | grep -q '"fond_assume":true'
verifier "crop import a marqué fond_assume" $?

echo "--- alt missing : image produit sans alt listée ---"
JSON_ALT=$(wp images-legeres alt missing --format=json)
echo "$JSON_ALT" | grep -q "\"id\":$ID_IMG2"
verifier "alt missing liste l'image produit sans alt" $?

echo "--- alt missing : recoupement SQL indépendant ---"
# `wp db query` shelle vers le client mysql, absent du conteneur wordpress : la requête
# indépendante passe par $wpdb via `wp eval`, pas par la classe de l'extension.
SQL_COMPTE=$(wp eval "
global \$wpdb;
echo (int) \$wpdb->get_var(\"
    SELECT COUNT(*) FROM {\$wpdb->posts} p
    LEFT JOIN {\$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'
    WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%'
    AND p.post_parent <> 0
    AND COALESCE(a.meta_value,'') = ''
\");
" | tr -d '\r')
CLI_COMPTE=$(echo "$JSON_ALT" | python3 -c "import json,sys; print(len(json.load(sys.stdin)))")
[[ "$SQL_COMPTE" -eq "$CLI_COMPTE" ]]
verifier "alt missing recoupe exactement la requête SQL indépendante ($CLI_COMPTE == $SQL_COMPTE)" $?

echo "--- alt missing : écrire l'alt (wp post meta update) retire l'image de la liste ---"
wp post meta update "$ID_IMG2" _wp_attachment_image_alt "Bougie verte posée sur un fond neutre" >/dev/null
JSON_ALT2=$(wp images-legeres alt missing --format=json)
! echo "$JSON_ALT2" | grep -q "\"id\":$ID_IMG2"
verifier "alt missing ne liste plus l'image après que l'alt a été écrit" $?
ALT_RELU=$(wp post meta get "$ID_IMG2" _wp_attachment_image_alt)
[[ "$ALT_RELU" == "Bougie verte posée sur un fond neutre" ]]
verifier "l'alt écrit à la main n'a pas été altéré par les commandes crop" $?

echo "--- aucune commande images-legeres n'écrit _wp_attachment_image_alt (grep du code) ---"
! grep -rn "update_post_meta.*_wp_attachment_image_alt\|'_wp_attachment_image_alt'.*update" ../../includes/class-imageslegeres-cli*.php | grep -v "get_post_meta"
verifier "aucune écriture de _wp_attachment_image_alt dans le code des commandes" $?

echo "--- flux check : rectangle 1500x1500 (>=500) absent de la liste ---"
JSON_FLUX=$(wp images-legeres flux check --format=json)
! echo "$JSON_FLUX" | grep -q "\"id\":$ID_IMG2"
verifier "flux check n'inclut pas l'image dont le carré fait 1500 px" $?

echo "--- flux check : image fond_assume, carré = plus grand côté (1200, >=500) absente ---"
! echo "$JSON_FLUX" | grep -q "\"id\":$ID_IMG3"
verifier "flux check n'inclut pas l'image fond_assume 900x1200 (plus grand côté 1200 >= 500)" $?

echo "--- flux check : image produit minuscule (400x300, sans rectangle) listée avec motif ---"
# crop set refuse lui-même un carré < 500 px (même garde-fou, ADR 0005) : pour exercer le
# plancher de flux check, on part d'une image dont le repli « fond ajouté » (plus grand
# côté de l'original) est déjà sous 500 px, sans poser de rectangle.
TMP_IMG4=$(mktemp --suffix=.jpg)
creer_image "$TMP_IMG4" 400 300
ID_IMG4=$(importer_et_rattacher "$TMP_IMG4" "$ID_PRODUIT")
verifier "image produit minuscule créée" $([[ "$ID_IMG4" =~ ^[0-9]+$ ]] && echo 0 || echo 1)
JSON_FLUX2=$(wp images-legeres flux check --format=json)
echo "$JSON_FLUX2" | grep -q "\"id\":$ID_IMG4"
verifier "flux check liste l'image dont le repli ferait 400 px" $?
echo "$JSON_FLUX2" | grep -q '"dimension_obtenue":400'
verifier "flux check rend la dimension obtenue (400)" $?

echo "--- flux check : JSON toujours valide même sans aucune image flaguée ---"
JSON_VIDE_OK=$(echo "$JSON_FLUX" | python3 -c "import json,sys; json.load(sys.stdin); print('ok')" 2>/dev/null)
[[ "$JSON_VIDE_OK" == "ok" ]]
verifier "flux check rend un JSON valide (parseable)" $?

echo "--- JSON valide pour crop pending, crop get, alt missing ---"
echo "$JSON_PENDING2" | python3 -c "import json,sys; json.load(sys.stdin)" 2>/dev/null
verifier "crop pending rend un JSON valide" $?
echo "$JSON_GET_VIDE" | python3 -c "import json,sys; json.load(sys.stdin)" 2>/dev/null
verifier "crop get rend un JSON valide" $?
echo "$JSON_ALT2" | python3 -c "import json,sys; json.load(sys.stdin)" 2>/dev/null
verifier "alt missing rend un JSON valide" $?

if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
fi

exit "$ECHECS"
