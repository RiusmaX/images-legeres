#!/usr/bin/env bash
# Test exécuté dans le conteneur `cli` de tests/env/compose.yml :
#   docker compose -f tests/env/compose.yml run --rm cli bash /var/www/html/wp-content/plugins/images-legeres/tests/test-activation.sh
#
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides) : la suite
# désinstalle l'extension et vérifie qu'il ne reste aucun orphelin. Ne pas enchaîner les
# suites sur la même instance. Contrairement aux cinq autres, celle-ci tourne DANS le
# conteneur `cli` (elle ne touche à aucune image, donc le délégué Imagick manquant ne la
# gêne pas) : la lancer depuis l'hôte la fait échouer intégralement, faute de `wp`.
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

echo "--- réactivation puis désinstallation (niveau 3) ---"
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

echo "--- contrôle des drop-ins ---"
if [[ -f /var/www/html/wp-content/object-cache.php || -f /var/www/html/wp-content/advanced-cache.php ]]; then
  verifier "aucun drop-in posé" 1
else
  verifier "aucun drop-in posé" 0
fi

echo "--- tout rendre, puis contrôle d'orphelins ---"
# « Tout rendre » est une action de l'écran d'administration, jouée pendant que l'extension
# est active (admin_init exige le plugin chargé) : on la déclenche ici par `wp eval`, qui
# appelle le même code que le bouton, sans passer par le formulaire HTTP.
$WP plugin activate images-legeres
ORPHELINS_AVANT=$($WP images-legeres orphelins --format=json)
echo "orphelins (extension active, avant tout rendre, attendu non vide) : $ORPHELINS_AVANT"

$WP eval 'ImagesLegeres_Desinstallation::tout_rendre();'
verifier "tout rendre sans erreur WP-CLI" $?

if $WP db query "SHOW TABLES LIKE '%imageslegeres_fichiers'" | grep -q imageslegeres_fichiers; then
  verifier "table supprimée après tout rendre" 1
else
  verifier "table supprimée après tout rendre" 0
fi

# tout_rendre() appelle desinstaller() mais ne désactive pas le plugin : son code reste
# chargé, la commande orphelins reste donc disponible pour prouver l'état final.
ORPHELINS_APRES=$($WP images-legeres orphelins --format=json)
echo "orphelins (après tout rendre, attendu []) : $ORPHELINS_APRES"
if [[ "$ORPHELINS_APRES" == "[]" ]]; then
  verifier "wp images-legeres orphelins rend une liste vide" 0
else
  verifier "wp images-legeres orphelins rend une liste vide" 1
fi

$WP plugin deactivate images-legeres

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
