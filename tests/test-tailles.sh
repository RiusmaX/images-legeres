#!/usr/bin/env bash
# Plusieurs tailles natives pour un même carré harmonisé (0.2.3), lancé depuis l'HÔTE.
# EXIGE UN WORDPRESS JETABLE NEUF (tests/env/up.sh sur des volumes vides).
# Usage : IL_HTTP_PORT=8099 bash tests/test-tailles.sh
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/env"

wp() { docker compose -f compose.yml run --rm cli wp --allow-root --path=/var/www/html "$@" | tr -d '\r'; }

ECHECS=0
etape() {
  local sortie
  sortie=$(wp eval-file /var/www/html/wp-content/plugins/images-legeres/tests/tailles.php "$1" 2>&1)
  local rc=$?
  echo "$sortie"
  ECHECS=$((ECHECS + $(grep -c '^FAIL' <<<"$sortie")))
  # Une étape coupée en route (erreur fatale) ne doit pas passer pour verte.
  if [[ $rc -ne 0 ]] || ! grep -q "^FIN $1\$" <<<"$sortie"; then
    echo "FAIL - étape $1 interrompue (code $rc)"
    ECHECS=$((ECHECS + 1))
  fi
}

# Tailles de monkobougie.fr : fiche produit en 1024 px.
wp option update woocommerce_single_image_width 1024 >/dev/null
wp option update woocommerce_thumbnail_image_width 300 >/dev/null
wp option update woocommerce_thumbnail_cropping 1:1 >/dev/null

for e in substitution migration-semer migration-verifier suppression ordre; do
  echo "--- $e ---"
  etape "$e"
done

echo "échecs : $ECHECS"
[[ $ECHECS -eq 0 ]]
