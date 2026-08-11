#!/bin/bash
# Prepares the WordPress.org SVN working copy for a release.
#
# It rebuilds dist/staging, syncs it into <svn>/trunk and schedules the
# adds/deletes, then STOPS and prints the two commits for you to run.
# It deliberately never runs `svn commit`: that needs your wordpress.org SVN
# password, which does not belong in a script or in this repo.
#
# The working copy lives outside the git repo on purpose - SVN's .svn/ metadata
# has no business inside a git checkout, and trunk/ must contain only the files
# that ship.
#
# Usage: ./release-svn.sh [svn-working-copy-dir]

set -eu

cd "$(dirname "$0")"

SLUG="gatalyn-login-approval"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
SVN_DIR="${1:-$(cd .. && pwd)/${SLUG}-svn}"
STAGING="$(pwd)/dist/staging/${SLUG}"

if ! command -v svn >/dev/null 2>&1; then
    echo "[ERROR] No se encontró 'svn' en el PATH. Instálalo con:" >&2
    echo "        winget install --id Slik.Subversion" >&2
    echo "        (y abre una terminal nueva para que el PATH se refresque)" >&2
    exit 1
fi

# The version must match in both places or WordPress.org will serve a release
# that the plugin header disagrees with.
PLUGIN_VERSION="$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "${SLUG}.php" | tr -d '[:space:]')"
STABLE_TAG="$(sed -n 's/^Stable tag: *\(.*\)$/\1/p' readme.txt | tr -d '[:space:]')"

if [ -z "$PLUGIN_VERSION" ] || [ "$PLUGIN_VERSION" != "$STABLE_TAG" ]; then
    echo "[ERROR] Version del plugin ('${PLUGIN_VERSION}') y Stable tag ('${STABLE_TAG}') no coinciden." >&2
    exit 1
fi

echo "[1/4] Reconstruyendo dist/staging..."
./build-dist.sh >/dev/null

echo "[2/4] Working copy SVN en ${SVN_DIR}"
if [ -d "${SVN_DIR}/.svn" ]; then
    svn update "${SVN_DIR}"
else
    svn checkout "${SVN_URL}" "${SVN_DIR}"
fi

echo "[3/4] Sincronizando trunk/..."
mkdir -p "${SVN_DIR}/trunk"
# Wipe everything except .svn/ so files deleted from the plugin also disappear
# from trunk, then copy the freshly staged payload back in.
find "${SVN_DIR}/trunk" -mindepth 1 -maxdepth 1 ! -name '.svn' -exec rm -rf {} +
cp -R "${STAGING}/." "${SVN_DIR}/trunk/"

cd "${SVN_DIR}"
# '?' = new file, '!' = file gone from disk. SVN needs both told explicitly.
svn status | awk '/^\?/ {print $2}' | while read -r f; do svn add "$f" --quiet; done
svn status | awk '/^!/  {print $2}' | while read -r f; do svn rm "$f" --quiet; done

echo "[4/4] Pendiente de commit. Contenido de trunk/:"
svn status

cat <<EOF

--------------------------------------------------------------------
Ejecuta tú estos dos comandos (piden tu contraseña SVN de wordpress.org,
que NO es la de tu cuenta: se genera en Account & Security del perfil):

  cd "${SVN_DIR}"
  svn commit -m "Release ${PLUGIN_VERSION}" --username sergiopw
  svn copy trunk "tags/${PLUGIN_VERSION}"
  svn commit -m "Tag ${PLUGIN_VERSION}" --username sergiopw

El segundo es el que publica: WordPress.org sirve la version indicada en
'Stable tag', y solo la sirve si existe tags/${PLUGIN_VERSION}.
--------------------------------------------------------------------
EOF
