#!/usr/bin/env bash
# Aucune réécriture sur les requêtes d'édition et d'aperçu (0.2.3), lancé depuis l'HÔTE.
# EXIGE UN WORDPRESS JETABLE (tests/env/up.sh).
# Usage : IL_HTTP_PORT=8099 bash tests/test-edition.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"
BASE="http://localhost:${IL_HTTP_PORT:-8081}"

wp() { docker compose -f compose.yml run --rm cli wp --allow-root --path=/var/www/html "$@" | tr -d '\r'; }

ECHECS=0
verifier() {
  if [[ "$2" -eq 0 ]]; then echo "OK   - $1"; else echo "FAIL - $1"; ECHECS=$((ECHECS + 1)); fi
}

echo "--- préparation : page témoin, frère .webp, harmonisée inventoriée ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts());' >/dev/null
wp eval 'ImagesLegeres_Reglages::enregistrer(array("reecriture_sortie_active" => true, "mode_apercu_actif" => false, "mode_compatible_actif" => false, "harmonisation_produit_active" => true));' >/dev/null

read -r ID_PAGE URL_SEULE URL_NATIVE <<<"$(wp eval '
$u = wp_upload_dir(); $d = trailingslashit( $u["basedir"] ) . "edition/";
wp_mkdir_p( $d );
foreach ( array( "seule.jpg", "seule.jpg.webp", "native.jpg", "native-harmonisee-temoin.jpg" ) as $f ) { file_put_contents( $d . $f, "temoin" ); }
ImagesLegeres_Harmonisees::enregistrer( array( "attachment_id" => 999999, "taille" => "large", "chemin_natif" => "edition/native.jpg", "chemin_harmonise" => "edition/native-harmonisee-temoin.jpg", "largeur" => 600, "hauteur" => 600 ) );
$b = $u["baseurl"] . "/edition/";
$id = wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_title" => "Temoin edition",
  "post_content" => "<img src=\"{$b}seule.jpg\" alt=\"\">\n<img src=\"{$b}native.jpg\" alt=\"\">" ) );
echo "$id {$b}seule.jpg {$b}native.jpg";
')"
verifier "page témoin créée (obtenu ${ID_PAGE:-rien})" $([[ "${ID_PAGE:-}" =~ ^[0-9]+$ ]] && echo 0 || echo 1)

echo "--- cas par cas, en administrateur ---"
for cas in temoin temoin-preview-false et_fb app_window et_pb_preview et_pb_preview-post \
  et_pb_preview_nonce et_tb preview preview_id is_admin ajax rest json customizer; do
  SORTIE=$(wp --user=admin eval-file /var/www/html/wp-content/plugins/images-legeres/tests/edition.php "$cas" "$ID_PAGE" 2>&1)
  echo "$SORTIE"
  # Un cas coupé en route ne doit pas passer pour vert.
  grep -qE "^(OK  |FAIL) - $cas :" <<<"$SORTIE" || verifier "cas $cas interrompu" 1
  ECHECS=$((ECHECS + $(grep -c '^FAIL - .* :' <<<"$SORTIE")))
done

echo "--- les mêmes paramètres, sans droit de modifier : ignorés ---"
wp user create il-abonne il-abonne@example.test --role=subscriber --porcelain >/dev/null
ID_EDITEUR=$(wp user create il-editeur il-editeur@example.test --role=editor --porcelain)
for qui in anonyme il-abonne; do
  UTILISATEUR=(); [[ $qui == anonyme ]] || UTILISATEUR=(--user="$qui")
  for cas in et_fb app_window et_pb_preview et_pb_preview-post et_pb_preview_nonce et_tb preview preview_id; do
    SORTIE=$(wp "${UTILISATEUR[@]}" eval-file /var/www/html/wp-content/plugins/images-legeres/tests/edition.php "$cas" "$ID_PAGE" public 2>&1)
    echo "$qui : $SORTIE"
    grep -qE "^OK   - $cas : réécriture" <<<"$SORTIE" || verifier "$qui $cas : réécriture attendue" 1
  done
done

echo "--- intégration HTTP ---"
ENTETE=$(mktemp); ENTETE_EDITEUR=$(mktemp); trap 'rm -f "$ENTETE" "$ENTETE_EDITEUR"' EXIT
cookie() { wp eval '$e = time() + 600; $j = WP_Session_Tokens::get_instance( '"$1"' )->create( $e ); echo "Cookie: " . LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie( '"$1"', $e, "logged_in", $j );'; }
cookie 1 >"$ENTETE"
cookie "$ID_EDITEUR" >"$ENTETE_EDITEUR"

# Sans suivre les redirections : une redirection qui ôterait le paramètre mesurerait la page publique.
page() { curl -s "$@" "$BASE/?page_id=$ID_PAGE${SUFFIXE:-}"; }

for qui in anonyme administrateur; do
  if [[ $qui == administrateur ]]; then HTML=$(SUFFIXE= page -H @"$ENTETE"); else HTML=$(SUFFIXE= page); fi
  verifier "témoin $qui : frère .webp servi" $(grep -qF "$URL_SEULE.webp" <<<"$HTML" && echo 0 || echo 1)
  verifier "témoin $qui : harmonisée servie" $(grep -qF 'native-harmonisee-temoin.jpg' <<<"$HTML" && echo 0 || echo 1)
done
# Pas de tube : `grep -q` couperait curl et `pipefail` ferait mentir le contrôle.
HTML=$(page -H @"$ENTETE")
verifier "la requête authentifiée est bien celle d'un administrateur" \
  $(grep -q 'wpadminbar' <<<"$HTML" && echo 0 || echo 1)

for suffixe in '&et_fb=1' '&et_fb=1&app_window=1' '&et_pb_preview=true' '&et_tb=1' '&preview_id=1'; do
  HTML=$(SUFFIXE=$suffixe page)
  verifier "anonyme $suffixe : frère .webp servi" $(grep -qF "$URL_SEULE.webp" <<<"$HTML" && echo 0 || echo 1)
  verifier "anonyme $suffixe : harmonisée servie" $(grep -qF 'native-harmonisee-temoin.jpg' <<<"$HTML" && echo 0 || echo 1)
done
HTML=$(SUFFIXE='&et_fb=1' page -H @"$ENTETE_EDITEUR")
verifier "éditeur connecté &et_fb=1 : session reconnue" $(grep -q 'wpadminbar' <<<"$HTML" && echo 0 || echo 1)
verifier "éditeur connecté &et_fb=1 : adresse .jpg d'origine servie" $(grep -qF "$URL_SEULE\"" <<<"$HTML" && echo 0 || echo 1)
verifier "éditeur connecté &et_fb=1 : ni .jpg.webp ni harmonisée" \
  $(grep -qE '\.jpg\.webp|harmonisee-temoin' <<<"$HTML" && echo 1 || echo 0)

# L'aperçu WordPress d'une page publiée est redirigé sans nonce : couvert cas par cas.
for suffixe in '&et_fb=1' '&et_fb=1&app_window=1' '&et_pb_preview=true'; do
  HTML=$(SUFFIXE=$suffixe page -H @"$ENTETE")
  verifier "administrateur $suffixe : adresse .jpg d'origine servie" $(grep -qF "$URL_SEULE\"" <<<"$HTML" && echo 0 || echo 1)
  verifier "administrateur $suffixe : adresse native servie" $(grep -qF "$URL_NATIVE\"" <<<"$HTML" && echo 0 || echo 1)
  verifier "administrateur $suffixe : ni .jpg.webp ni harmonisée" \
    $(grep -qE '\.jpg\.webp|harmonisee-temoin' <<<"$HTML" && echo 1 || echo 0)
done

echo "échecs : $ECHECS"
[[ $ECHECS -eq 0 ]]
