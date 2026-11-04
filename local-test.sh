#!/bin/sh
# Levanta un WordPress local con el plugin montado desde este repo.
# Uso: ./local-test.sh          (arranca y prepara)
#      ./local-test.sh --reset  (borra la base de datos y empieza de cero)
set -eu
cd "$(dirname "$0")"

if [ "${1:-}" = "--reset" ]; then
    docker compose down -v
fi

docker compose up -d db wordpress mailpit
docker compose run --rm wpcli sh /workspace/.docker/wp-cli-install.sh
