# Levanta un WordPress local con el plugin montado desde este repo.
# Uso: .\local-test.ps1         (arranca y prepara)
#      .\local-test.ps1 -Reset  (borra la base de datos y empieza de cero)
param([switch]$Reset)

Set-Location $PSScriptRoot

if ($Reset) {
    docker compose down -v
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

docker compose up -d db wordpress mailpit
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

docker compose run --rm wpcli sh /workspace/.docker/wp-cli-install.sh
exit $LASTEXITCODE
