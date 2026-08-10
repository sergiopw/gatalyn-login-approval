#!/bin/bash
# Build script to update plugin ZIP
#
# Delegates to build-dist.php: the previous inline Python step silently did
# nothing on machines where python3/python resolve to the Windows Store
# app-execution-alias stubs instead of a real interpreter (dist/ was never
# actually refreshed even though this script reported success).

cd "$(dirname "$0")" || exit 1

echo "[BUILD] Generando plugin ZIP..."

PHP_BIN="$(command -v php)"
if [ -z "$PHP_BIN" ] && [ -f "$HOME/.config/herd/bin/php.bat" ]; then
    # Windows .bat/.exe files don't carry the unix executable bit in Git Bash,
    # so check for existence rather than -x here.
    PHP_BIN="$HOME/.config/herd/bin/php.bat"
fi

if [ -z "$PHP_BIN" ]; then
    echo "[ERROR] No se encontró un ejecutable PHP en el PATH." >&2
    exit 1
fi

"$PHP_BIN" build-dist.php || exit 1

echo "[DONE] Listo para desplegar en Hostinger"
