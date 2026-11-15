#!/usr/bin/env bash
# Arrête et supprime le WordPress jetable (option --volumes pour repartir à zéro).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if [[ "${1:-}" == "--volumes" ]]; then
  docker compose -f compose.yml down --volumes
else
  docker compose -f compose.yml down
fi
