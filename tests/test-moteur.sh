#!/usr/bin/env bash
# Test du moteur de conversion/retaille (mission 13), lancé depuis l'HÔTE (pas dans le
# conteneur `cli` : son ImageMagick n'a pas le délégué WebP — queryFormats('WEBP') y rend un
# tableau vide, contre ['WEBP'] dans le conteneur `wordpress`, vérifié le 2026-11-15).
# wp-cli n'est pas installé dans le conteneur `wordpress` : ce script en récupère le phar
# depuis l'image `cli` et l'exécute avec le PHP du conteneur `wordpress`, pour avoir à la
# fois wp-cli et le bon Imagick. Ne modifie pas tests/env/.
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides). Les assertions
# comptent les pièces jointes et les lignes de la table : les images laissées par une autre
# suite les font échouer. Ne pas enchaîner les suites sur la même instance.
#
# Usage : bash tests/test-moteur.sh   (tests/env/up.sh doit déjà avoir tourné)
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

TEMOINS=/var/www/html/wp-content/plugins/images-legeres/tests/fixtures/tmp
UPLOADS=/var/www/html/wp-content/uploads
ECHECS=0

# Les témoins sont générés ICI, pas supposés présents : sans cet appel, la suite entière
# échoue sur un WordPress neuf faute de fichiers à importer, avant d'avoir testé quoi que ce
# soit (relevé par le contrôle qa du 2026-11-15, 29 échecs).
echo "--- préparation : génération des témoins ---"
wp eval-file /var/www/html/wp-content/plugins/images-legeres/tests/fixtures/generer-temoins.php

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

existe_dans_conteneur() {
  docker compose -f compose.yml exec -T wordpress test -f "$1"
}

poids_dans_conteneur() {
  docker compose -f compose.yml exec -T wordpress stat -c%s "$1" 2>/dev/null | tr -d '\r'
}

mtime_dans_conteneur() {
  docker compose -f compose.yml exec -T wordpress stat -c%Y "$1" 2>/dev/null | tr -d '\r'
}

importer() {
  wp media import "$TEMOINS/$1" --porcelain | tr -d '\r'
}

importer_direct() {
  # Contourne la liste blanche des types MIME (utile pour le SVG, jamais autorisé par
  # défaut) : copie le fichier dans uploads/ puis crée l'attachement sans passer par
  # wp_handle_upload.
  local fichier="$1" mime="$2"
  wp eval "
\$uploads = wp_upload_dir();
\$nom = basename('$fichier');
\$destination = trailingslashit(\$uploads['path']) . \$nom;
copy('$TEMOINS/$fichier', \$destination);
\$id = wp_insert_attachment(array(
  'post_mime_type' => '$mime',
  'post_title'     => \$nom,
  'post_status'    => 'inherit',
), \$destination);
echo \$id;
" | tr -d '\r'
}

echo "--- préparation : réglages par défaut, pas de retaille automatique ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(ImagesLegeres_Reglages::defauts());' >/dev/null

echo "--- import des témoins ---"
ID_2560=$(importer jpeg-2560.jpg)
ID_471=$(importer jpeg-471.jpg)
ID_PNG_OPAQUE=$(importer png-opaque.png)
ID_PNG_TRANSPARENT=$(importer png-transparent.png)
ID_WEBP=$(importer webp-deja-converti.webp)
ID_SVG=$(importer_direct image.svg image/svg+xml)
ID_GIF_ANIME=$(importer gif-anime.gif)
ID_GIF_STATIQUE=$(importer gif-statique.gif)
ID_PRODUIT=$(importer jpeg-produit.jpg)
ID_PNG_PRODUIT=$(importer png-produit-transparent.png)
ID_BRUIT=$(importer png-bruit-incompressible.png)
ID_MANQUANT=$(importer jpeg-manquant.jpg)

for nom in ID_2560 ID_471 ID_PNG_OPAQUE ID_PNG_TRANSPARENT ID_WEBP ID_SVG ID_GIF_ANIME ID_GIF_STATIQUE ID_PRODUIT ID_PNG_PRODUIT ID_BRUIT ID_MANQUANT; do
  valeur="${!nom}"
  if [[ "$valeur" =~ ^[0-9]+$ ]]; then
    verifier "import de $nom (id=$valeur)" 0
  else
    verifier "import de $nom" 1
  fi
done

echo "--- rattachement des témoins produit à un produit WooCommerce (qualité 90) ---"
ID_PRODUIT_WC=$(wp post create --post_type=product --post_title="Produit témoin" --post_status=publish --porcelain | tr -d '\r')
verifier "produit témoin créé" $([[ "$ID_PRODUIT_WC" =~ ^[0-9]+$ ]] && echo 0 || echo 1)
wp post update "$ID_PRODUIT" --post_parent="$ID_PRODUIT_WC" >/dev/null
wp post update "$ID_PNG_PRODUIT" --post_parent="$ID_PRODUIT_WC" >/dev/null

echo "--- fichier manquant : suppression du fichier avant traitement ---"
FICHIER_MANQUANT=$(wp eval "echo get_attached_file($ID_MANQUANT);" | tr -d '\r')
docker compose -f compose.yml exec -T wordpress rm -f "$FICHIER_MANQUANT"

echo "--- JPEG 471 px : conversion simple ---"
CHEMIN_471=$(wp eval "echo get_attached_file($ID_471);" | tr -d '\r')
POIDS_AVANT_471=$(poids_dans_conteneur "$CHEMIN_471")
wp images-legeres convert "$ID_471" >/tmp/resultat-471.txt 2>&1
verifier "convert 471 sans erreur WP-CLI" $?
if existe_dans_conteneur "${CHEMIN_471}.webp"; then
  verifier "frère .webp créé pour le JPEG 471" 0
else
  verifier "frère .webp créé pour le JPEG 471" 1
fi
existe_dans_conteneur "$CHEMIN_471"
verifier "original JPEG 471 toujours présent (jamais supprimé)" $?
POIDS_WEBP_471=$(poids_dans_conteneur "${CHEMIN_471}.webp")
if [[ -n "$POIDS_WEBP_471" && "$POIDS_WEBP_471" -lt "$POIDS_AVANT_471" ]]; then
  verifier "le frère .webp est plus léger que l'original (471)" 0
else
  verifier "le frère .webp est plus léger que l'original (471)" 1
fi
FORMAT_WEBP_471=$(wp eval "echo (new Imagick('${CHEMIN_471}.webp'))->getImageFormat();" | tr -d '\r')
verifier "format réel du frère = WEBP (471)" $([[ "$FORMAT_WEBP_471" == "WEBP" ]] && echo 0 || echo 1)

echo "--- PNG opaque : conversion ---"
wp images-legeres convert "$ID_PNG_OPAQUE" >/dev/null 2>&1
CHEMIN_PNG_OPAQUE=$(wp eval "echo get_attached_file($ID_PNG_OPAQUE);" | tr -d '\r')
existe_dans_conteneur "${CHEMIN_PNG_OPAQUE}.webp"
verifier "frère .webp créé pour le PNG opaque" $?

echo "--- PNG transparent : transparence conservée ---"
wp images-legeres convert "$ID_PNG_TRANSPARENT" >/dev/null 2>&1
CHEMIN_PNG_TRANSPARENT=$(wp eval "echo get_attached_file($ID_PNG_TRANSPARENT);" | tr -d '\r')
ALPHA=$(wp eval "
\$i = new Imagick('${CHEMIN_PNG_TRANSPARENT}.webp');
echo \$i->getImageAlphaChannel() ? '1' : '0';
" | tr -d '\r')
verifier "canal alpha présent sur le frère .webp (PNG transparent)" $([[ "$ALPHA" == "1" ]] && echo 0 || echo 1)

echo "--- WebP déjà converti : jamais retouché ---"
CHEMIN_WEBP=$(wp eval "echo get_attached_file($ID_WEBP);" | tr -d '\r')
POIDS_AVANT_WEBP=$(poids_dans_conteneur "$CHEMIN_WEBP")
wp images-legeres convert "$ID_WEBP" >/dev/null 2>&1
if existe_dans_conteneur "${CHEMIN_WEBP}.webp"; then
  verifier "aucun frère .webp.webp créé pour un fichier déjà WebP" 1
else
  verifier "aucun frère .webp.webp créé pour un fichier déjà WebP" 0
fi
POIDS_APRES_WEBP=$(poids_dans_conteneur "$CHEMIN_WEBP")
verifier "fichier WebP d'origine inchangé" $([[ "$POIDS_AVANT_WEBP" == "$POIDS_APRES_WEBP" ]] && echo 0 || echo 1)

echo "--- SVG : jamais touché ---"
CHEMIN_SVG=$(wp eval "echo get_attached_file($ID_SVG);" | tr -d '\r')
wp images-legeres convert "$ID_SVG" >/dev/null 2>&1
if existe_dans_conteneur "${CHEMIN_SVG}.webp"; then
  verifier "aucun frère .webp créé pour le SVG" 1
else
  verifier "aucun frère .webp créé pour le SVG" 0
fi

echo "--- GIF animé : jamais touché ---"
CHEMIN_GIF_ANIME=$(wp eval "echo get_attached_file($ID_GIF_ANIME);" | tr -d '\r')
wp images-legeres convert "$ID_GIF_ANIME" >/dev/null 2>&1
if existe_dans_conteneur "${CHEMIN_GIF_ANIME}.webp"; then
  verifier "aucun frère .webp créé pour le GIF animé" 1
else
  verifier "aucun frère .webp créé pour le GIF animé" 0
fi

echo "--- GIF statique : converti (seuls les GIF animés sont exclus) ---"
CHEMIN_GIF_STATIQUE=$(wp eval "echo get_attached_file($ID_GIF_STATIQUE);" | tr -d '\r')
wp images-legeres convert "$ID_GIF_STATIQUE" >/dev/null 2>&1
existe_dans_conteneur "${CHEMIN_GIF_STATIQUE}.webp"
verifier "frère .webp créé pour le GIF statique" $?

echo "--- fichier manquant : état erreur, pas de plantage ---"
SORTIE_MANQUANT=$(wp images-legeres convert "$ID_MANQUANT" 2>&1)
verifier "convert sur fichier manquant ne plante pas (code de sortie 0)" $?
if echo "$SORTIE_MANQUANT" | grep -qi "erreur"; then
  verifier "fichier manquant signalé en erreur" 0
else
  verifier "fichier manquant signalé en erreur" 1
fi

echo "--- qualité produit (90) vs générale (82) ---"
# getImageCompressionQuality() n'est pas fiable en lecture sur un WebP déjà écrit (WebP ne
# réexpose pas la qualité d'encodage à la relecture) : on compare plutôt le poids obtenu sur
# le MÊME contenu, une fois rattaché à un produit (qualité 90, moins compressé) et une fois
# non rattaché (qualité 82) — la version qualité 90 doit être au moins aussi lourde.
ID_QUALITE_PRODUIT=$(importer jpeg-produit.jpg)
wp post update "$ID_QUALITE_PRODUIT" --post_parent="$ID_PRODUIT_WC" >/dev/null
ID_QUALITE_GENERALE=$(importer jpeg-produit.jpg)
wp images-legeres convert "$ID_QUALITE_PRODUIT" >/dev/null 2>&1
wp images-legeres convert "$ID_QUALITE_GENERALE" >/dev/null 2>&1
CHEMIN_QUALITE_PRODUIT=$(wp eval "echo get_attached_file($ID_QUALITE_PRODUIT);" | tr -d '\r')
CHEMIN_QUALITE_GENERALE=$(wp eval "echo get_attached_file($ID_QUALITE_GENERALE);" | tr -d '\r')
POIDS_QUALITE_PRODUIT=$(poids_dans_conteneur "${CHEMIN_QUALITE_PRODUIT}.webp")
POIDS_QUALITE_GENERALE=$(poids_dans_conteneur "${CHEMIN_QUALITE_GENERALE}.webp")
if [[ -n "$POIDS_QUALITE_PRODUIT" && -n "$POIDS_QUALITE_GENERALE" && "$POIDS_QUALITE_PRODUIT" -gt "$POIDS_QUALITE_GENERALE" ]]; then
  verifier "qualité 90 (produit, $POIDS_QUALITE_PRODUIT o) > qualité 82 (général, $POIDS_QUALITE_GENERALE o) sur le même contenu" 0
else
  verifier "qualité 90 (produit, $POIDS_QUALITE_PRODUIT o) > qualité 82 (général, $POIDS_QUALITE_GENERALE o) sur le même contenu" 1
fi

wp images-legeres convert "$ID_PRODUIT" >/dev/null 2>&1

echo "--- transparence + qualité produit en même temps ---"
wp images-legeres convert "$ID_PNG_PRODUIT" >/dev/null 2>&1
CHEMIN_PNG_PRODUIT=$(wp eval "echo get_attached_file($ID_PNG_PRODUIT);" | tr -d '\r')
if existe_dans_conteneur "${CHEMIN_PNG_PRODUIT}.webp"; then
  ALPHA_PRODUIT=$(wp eval "
\$i = new Imagick('${CHEMIN_PNG_PRODUIT}.webp');
echo \$i->getImageAlphaChannel() ? '1' : '0';
" | tr -d '\r')
  verifier "canal alpha conservé sur une image produit" $([[ "$ALPHA_PRODUIT" == "1" ]] && echo 0 || echo 1)
else
  verifier "canal alpha conservé sur une image produit" 1
fi

echo "--- garde-fou de gain 10 % ---"
wp eval 'ImagesLegeres_Reglages::enregistrer(array("gain_minimal_pourcent" => 99));' >/dev/null
ID_GAIN=$(importer jpeg-471.jpg)
CHEMIN_GAIN=$(wp eval "echo get_attached_file($ID_GAIN);" | tr -d '\r')
RESULTAT_GAIN=$(wp images-legeres convert "$ID_GAIN" 2>&1)
wp eval 'ImagesLegeres_Reglages::enregistrer(array("gain_minimal_pourcent" => 10));' >/dev/null
if echo "$RESULTAT_GAIN" | grep -qi "ignore" && ! existe_dans_conteneur "${CHEMIN_GAIN}.webp"; then
  verifier "sous le seuil de gain, le frère n'est pas gardé" 0
else
  verifier "sous le seuil de gain, le frère n'est pas gardé" 1
fi

echo "--- idempotence : relancer ne refait rien ---"
MTIME_AVANT=$(mtime_dans_conteneur "${CHEMIN_471}.webp")
sleep 2
wp images-legeres convert "$ID_471" >/dev/null 2>&1
MTIME_APRES=$(mtime_dans_conteneur "${CHEMIN_471}.webp")
verifier "un second appel ne réécrit pas le frère déjà à jour" $([[ "$MTIME_AVANT" == "$MTIME_APRES" ]] && echo 0 || echo 1)

echo "--- retaille : original 2560 px, dimension maximale 1920 px, copie de sécurité ---"
CHEMIN_2560=$(wp eval "echo get_attached_file($ID_2560);" | tr -d '\r')
LARGEUR_AVANT=$(wp eval "echo (new Imagick('$CHEMIN_2560'))->getImageWidth();" | tr -d '\r')
verifier "original 2560 px avant retaille" $([[ "$LARGEUR_AVANT" == "2560" ]] && echo 0 || echo 1)

wp eval 'ImagesLegeres_Reglages::enregistrer(array("retaille_originaux_active" => true));' >/dev/null
wp images-legeres convert "$ID_2560" >/dev/null 2>&1
wp eval 'ImagesLegeres_Reglages::enregistrer(array("retaille_originaux_active" => false));' >/dev/null

LARGEUR_APRES=$(wp eval "echo (new Imagick('$CHEMIN_2560'))->getImageWidth();" | tr -d '\r')
verifier "original retaillé à 1920 px au plus" $([[ "$LARGEUR_APRES" =~ ^[0-9]+$ && "$LARGEUR_APRES" -le 1920 ]] && echo 0 || echo 1)

CHEMIN_RELATIF=$(wp eval "echo ImagesLegeres_Moteur::chemin_relatif('$CHEMIN_2560');" | tr -d '\r')
CHEMIN_SAUVEGARDE="$UPLOADS/images-legeres/originaux/$CHEMIN_RELATIF"
if existe_dans_conteneur "$CHEMIN_SAUVEGARDE"; then
  LARGEUR_SAUVEGARDE=$(wp eval "echo (new Imagick('$CHEMIN_SAUVEGARDE'))->getImageWidth();" | tr -d '\r')
  verifier "copie de sécurité de l'original présente, à 2560 px" $([[ "$LARGEUR_SAUVEGARDE" == "2560" ]] && echo 0 || echo 1)
else
  verifier "copie de sécurité de l'original présente, à 2560 px" 1
fi

ETAT_RETAILLE=$(wp eval "
global \$wpdb;
\$table = \$wpdb->prefix . 'imageslegeres_fichiers';
echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT retaille FROM {\$table} WHERE chemin = %s\", '$CHEMIN_RELATIF'));
" | tr -d '\r')
verifier "table d'état : ligne marquée retaille = 1" $([[ "$ETAT_RETAILLE" == "1" ]] && echo 0 || echo 1)

echo "--- relancer la retaille sur un original déjà sous la dimension maximale : idempotent ---"
TAILLE_SAUVEGARDE_AVANT=$(poids_dans_conteneur "$CHEMIN_SAUVEGARDE")
wp eval 'ImagesLegeres_Reglages::enregistrer(array("retaille_originaux_active" => true));' >/dev/null
wp images-legeres convert "$ID_2560" >/dev/null 2>&1
wp eval 'ImagesLegeres_Reglages::enregistrer(array("retaille_originaux_active" => false));' >/dev/null
TAILLE_SAUVEGARDE_APRES=$(poids_dans_conteneur "$CHEMIN_SAUVEGARDE")
verifier "relancer la retaille ne recopie pas une nouvelle fois l'original" $([[ "$TAILLE_SAUVEGARDE_AVANT" == "$TAILLE_SAUVEGARDE_APRES" ]] && echo 0 || echo 1)

echo "--- table d'état : lignes 'fait' pour les témoins convertis ---"
TOTAL_FAIT=$(wp eval "
global \$wpdb;
\$table = \$wpdb->prefix . 'imageslegeres_fichiers';
echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$table} WHERE etat = 'fait'\");
" | tr -d '\r')
if [[ "$TOTAL_FAIT" =~ ^[0-9]+$ && "$TOTAL_FAIT" -ge 5 ]]; then
  verifier "la table d'état contient plusieurs lignes 'fait'" 0
else
  verifier "la table d'état contient plusieurs lignes 'fait'" 1
fi

echo "---"
if [[ "$ECHECS" -eq 0 ]]; then
  echo "TOUS LES TESTS PASSENT"
  exit 0
else
  echo "$ECHECS TEST(S) EN ÉCHEC"
  exit 1
fi
