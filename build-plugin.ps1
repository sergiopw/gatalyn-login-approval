$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectName = 'gatalyn-login-approval'
$distDir = Join-Path $repoRoot 'dist'
$stagingRoot = Join-Path $distDir 'staging'
$stagingProject = Join-Path $stagingRoot $projectName
$zipPath = Join-Path $distDir ($projectName + '.zip')

if (Test-Path $stagingRoot) {
    Remove-Item $stagingRoot -Recurse -Force
}

if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

New-Item -ItemType Directory -Path $stagingProject -Force | Out-Null

Copy-Item (Join-Path $repoRoot 'gatalyn-login-approval.php') (Join-Path $stagingProject 'gatalyn-login-approval.php')
Copy-Item (Join-Path $repoRoot 'uninstall.php') (Join-Path $stagingProject 'uninstall.php')
Copy-Item (Join-Path $repoRoot 'readme.txt') (Join-Path $stagingProject 'readme.txt')
if (Test-Path (Join-Path $repoRoot 'index.php')) {
    Copy-Item (Join-Path $repoRoot 'index.php') (Join-Path $stagingProject 'index.php')
}
Copy-Item (Join-Path $repoRoot 'includes') (Join-Path $stagingProject 'includes') -Recurse
Copy-Item (Join-Path $repoRoot 'assets') (Join-Path $stagingProject 'assets') -Recurse

# Build ZIP with forward-slash entry names for Linux hosting compatibility.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open(
    $zipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)

try {
    Get-ChildItem -Path $stagingProject -Recurse -File | ForEach-Object {
        $fullPath = $_.FullName
        $entryName = $fullPath.Substring($stagingRoot.Length + 1).Replace('\', '/')

        $entry = $zip.CreateEntry(
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        )

        $entryStream = $entry.Open()
        $fileStream = [System.IO.File]::OpenRead($fullPath)

        try {
            $fileStream.CopyTo($entryStream)
        } finally {
            $fileStream.Dispose()
            $entryStream.Dispose()
        }
    }
} finally {
    $zip.Dispose()
}

Write-Output "Plugin ZIP generated: $zipPath"
