# PowerShell twin of release-svn.sh, for working from a plain PowerShell prompt
# (no Git Bash on the PATH). Same contract: it prepares the WordPress.org SVN
# working copy and STOPS before `svn commit`, which needs your wordpress.org SVN
# password and stays manual.
#
# Usage: .\release-svn.ps1 [-SvnDir <path>]

param(
    [string]$SvnDir
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$slug     = 'gatalyn-login-approval'
$svnUrl   = "https://plugins.svn.wordpress.org/$slug"
$staging  = Join-Path $repoRoot "dist\staging\$slug"

if (-not $SvnDir) {
    # Outside the git repo on purpose: SVN's .svn/ metadata has no business
    # inside a git checkout, and trunk/ must hold only the files that ship.
    $SvnDir = Join-Path (Split-Path -Parent $repoRoot) "$slug-svn"
}

if (-not (Get-Command svn -ErrorAction SilentlyContinue)) {
    Write-Error @"
No se encontro 'svn' en el PATH. Instalalo con:
    winget install --id Slik.Subversion -e
y abre una terminal NUEVA para que el PATH se refresque.
"@
}

# The version must match in both places or WordPress.org will serve a release
# that the plugin header disagrees with.
$versionMatch = Select-String -Path (Join-Path $repoRoot "$slug.php") -Pattern '^\s*\*\s*Version:\s*(.+)$' | Select-Object -First 1
$stableMatch  = Select-String -Path (Join-Path $repoRoot 'readme.txt') -Pattern '^Stable tag:\s*(.+)$' | Select-Object -First 1

if (-not $versionMatch -or -not $stableMatch) {
    Write-Error "No se pudo leer la version del plugin o el Stable tag."
}

$pluginVersion = $versionMatch.Matches[0].Groups[1].Value.Trim()
$stableTag     = $stableMatch.Matches[0].Groups[1].Value.Trim()

if ($pluginVersion -ne $stableTag) {
    Write-Error "Version del plugin ('$pluginVersion') y Stable tag ('$stableTag') no coinciden."
}

Write-Output "[1/4] Reconstruyendo dist/staging..."
& (Join-Path $repoRoot 'build-plugin.ps1') | Out-Null

Write-Output "[2/4] Working copy SVN en $SvnDir"
if (Test-Path (Join-Path $SvnDir '.svn')) {
    svn update $SvnDir
} else {
    svn checkout $svnUrl $SvnDir
}
if ($LASTEXITCODE -ne 0) { Write-Error "svn checkout/update fallo con codigo $LASTEXITCODE." }

Write-Output "[3/4] Sincronizando trunk/..."
$trunk = Join-Path $SvnDir 'trunk'
New-Item -ItemType Directory -Path $trunk -Force | Out-Null

# Wipe everything except .svn/ so files deleted from the plugin also disappear
# from trunk, then copy the freshly staged payload back in.
Get-ChildItem $trunk -Force | Where-Object { $_.Name -ne '.svn' } | Remove-Item -Recurse -Force
Copy-Item (Join-Path $staging '*') $trunk -Recurse -Force

Push-Location $SvnDir
try {
    # '?' = new file, '!' = file gone from disk. SVN needs both told explicitly.
    $status = svn status
    $status | Where-Object { $_ -match '^\?\s+(.+)$' } | ForEach-Object { svn add $Matches[1] --quiet }
    $status | Where-Object { $_ -match '^!\s+(.+)$' } | ForEach-Object { svn rm  $Matches[1] --quiet }

    Write-Output "[4/4] Pendiente de commit. Contenido de trunk/:"
    svn status
} finally {
    Pop-Location
}

Write-Output @"

--------------------------------------------------------------------
Ejecuta tu estos comandos (piden tu contrasena SVN de wordpress.org,
que NO es la de tu cuenta: se genera en Account & Security del perfil):

  cd "$SvnDir"
  svn commit -m "Release $pluginVersion" --username sergiopw
  svn copy trunk "tags/$pluginVersion"
  svn commit -m "Tag $pluginVersion" --username sergiopw

El segundo es el que publica: WordPress.org sirve la version indicada en
'Stable tag', y solo la sirve si existe tags/$pluginVersion.
--------------------------------------------------------------------
"@
