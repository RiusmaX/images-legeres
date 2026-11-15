#!/usr/bin/env bash
# Démarre un WordPress jetable local (ports 127.0.0.1 uniquement) avec WooCommerce installé,
# le plugin « images-legeres » monté en volume, et l'active.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

IL_HTTP_PORT="${IL_HTTP_PORT:-8081}"

docker compose -f compose.yml up -d

wp() {
  docker compose -f compose.yml run --rm cli wp --allow-root --path=/var/www/html "$@"
}

echo "Attente de la base de données..."
for _ in $(seq 1 30); do
  if docker compose -f compose.yml exec -T db mysqladmin ping -h 127.0.0.1 -u wordpress -pwordpress --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "Installation de WordPress..."
  wp core install \
    --url="http://localhost:${IL_HTTP_PORT}" \
    --title="Images légères — test" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.test \
    --skip-email

  echo "Installation de WooCommerce..."
  wp plugin install woocommerce --activate

  echo "Activation d'Images légères..."
  wp plugin activate images-legeres
fi

# WooCommerce s'installe en mode « bientôt disponible » (woocommerce_coming_soon=yes,
# woocommerce_store_pages_only=yes) tant que l'assistant de configuration n'a pas été
# terminé : un visiteur ANONYME reçoit alors la page d'attente à la place de la fiche
# produit, pendant qu'un administrateur connecté voit la vraie boutique. Sans cette ligne,
# toute vérification « ce que reçoit le visiteur » mesure la page d'attente et conclut à
# tort que la réécriture ne s'applique pas aux anonymes (F5, cas 3).
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_store_pages_only no >/dev/null

echo "WordPress jetable prêt : http://localhost:${IL_HTTP_PORT} (admin / admin)"
