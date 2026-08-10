<?php
/**
 * Builds the distributable plugin ZIP.
 *
 * Rewritten from the original Python step in build-dist.sh: on machines where
 * `python3`/`python` resolve to the Windows Store app-execution-alias stubs
 * (no real Python installed), that step silently did nothing and left
 * dist/staging + the ZIP stale. PHP is already a hard requirement for this
 * project, so this has no extra dependency.
 */

$repoRoot       = __DIR__;
$projectName    = 'gatalyn-login-approval';
$distDir        = $repoRoot . '/dist';
$stagingRoot    = $distDir . '/staging';
$stagingProject = $stagingRoot . '/' . $projectName;
$zipPath        = $distDir . '/' . $projectName . '.zip';

function gatalyn_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? gatalyn_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function gatalyn_rcopy(string $src, string $dst): void
{
    if (is_dir($src)) {
        if (!is_dir($dst)) {
            mkdir($dst, 0777, true);
        }
        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            gatalyn_rcopy($src . '/' . $item, $dst . '/' . $item);
        }
        return;
    }
    copy($src, $dst);
}

// 1. Clean up any previous build.
gatalyn_rrmdir($stagingRoot);
if (file_exists($zipPath)) {
    unlink($zipPath);
}

// 2. Copy source files into the staging directory.
if (!is_dir($distDir)) {
    mkdir($distDir, 0777, true);
}
mkdir($stagingProject, 0777, true);

$mainFile = $repoRoot . '/' . $projectName . '.php';
if (!file_exists($mainFile)) {
    fwrite(STDERR, "[ERROR] No se encontró {$mainFile}\n");
    exit(1);
}

copy($mainFile, $stagingProject . '/' . $projectName . '.php');
copy($repoRoot . '/uninstall.php', $stagingProject . '/uninstall.php');
copy($repoRoot . '/readme.txt', $stagingProject . '/readme.txt');
if (file_exists($repoRoot . '/index.php')) {
    copy($repoRoot . '/index.php', $stagingProject . '/index.php');
}
gatalyn_rcopy($repoRoot . '/includes', $stagingProject . '/includes');
gatalyn_rcopy($repoRoot . '/assets', $stagingProject . '/assets');

// 3. Zip the staged project directory (arcnames relative to dist/staging, so
//    the ZIP root is "gatalyn-login-approval/...", ready to drop into
//    wp-content/plugins/).
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "[ERROR] La extensión PHP 'zip' no está disponible.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[ERROR] No se pudo crear {$zipPath}\n");
    exit(1);
}

$stagingRootReal = realpath($stagingRoot);
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stagingProject, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    $filePath = $file->getRealPath();
    $arcName  = ltrim(str_replace('\\', '/', substr($filePath, strlen($stagingRootReal))), '/');
    $zip->addFile($filePath, $arcName);
}
$zip->close();

$sizeKb = round(filesize($zipPath) / 1024, 1);
echo "[OK] ZIP actualizado: " . basename($zipPath) . " ({$sizeKb} KB)\n";
