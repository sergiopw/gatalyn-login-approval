<?php
/**
 * Generates the WordPress.org directory artwork (icon + banner).
 *
 * These end up in the SVN repository's top-level assets/ directory, NOT in
 * trunk/ - they are directory listing graphics, not plugin code.
 *
 * Everything is drawn at 4x and resampled down: GD does not antialias filled
 * shapes, so supersampling is what keeps the padlock edges clean.
 *
 * Usage: php wporg-assets/build-assets.php
 */

const SS = 4; // supersampling factor

$outDir = __DIR__ . '/out';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$fontBold    = 'C:/Windows/Fonts/segoeuib.ttf';
$fontRegular = 'C:/Windows/Fonts/segoeui.ttf';

// Palette.
$INDIGO_DARK  = [0x14, 0x1C, 0x45];
$INDIGO_LIGHT = [0x2E, 0x40, 0x93];
$WHITE        = [0xFF, 0xFF, 0xFF];
$GREEN        = [0x15, 0x80, 0x3D];
$SUBTITLE     = [0xAF, 0xBC, 0xEE];
$TAGLINE      = [0x8B, 0x9C, 0xD8];

/** Allocates a colour from an [r,g,b] triple. */
function col($im, array $rgb)
{
    return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

/** Fills the canvas with a diagonal gradient between two colours. */
function gradient($im, int $w, int $h, array $from, array $to): void
{
    $span = $w + $h;
    for ($i = 0; $i < $span; $i++) {
        $t = $i / max(1, $span - 1);
        $c = imagecolorallocate(
            $im,
            (int) round($from[0] + ($to[0] - $from[0]) * $t),
            (int) round($from[1] + ($to[1] - $from[1]) * $t),
            (int) round($from[2] + ($to[2] - $from[2]) * $t)
        );
        // Anti-diagonal: every pixel where x+y == $i.
        imageline($im, $i, 0, 0, $i, $c);
    }
}

/** Draws a thick line with round caps, as a polygon plus two discs. */
function thickLine($im, float $x1, float $y1, float $x2, float $y2, float $width, $color): void
{
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $len = sqrt($dx * $dx + $dy * $dy);
    if ($len < 0.0001) {
        return;
    }
    $nx = -$dy / $len * $width / 2;
    $ny = $dx / $len * $width / 2;

    imagefilledpolygon($im, array(
        (int) round($x1 + $nx), (int) round($y1 + $ny),
        (int) round($x2 + $nx), (int) round($y2 + $ny),
        (int) round($x2 - $nx), (int) round($y2 - $ny),
        (int) round($x1 - $nx), (int) round($y1 - $ny),
    ), $color);

    imagefilledellipse($im, (int) round($x1), (int) round($y1), (int) $width, (int) $width, $color);
    imagefilledellipse($im, (int) round($x2), (int) round($y2), (int) $width, (int) $width, $color);
}

/** Draws a filled rectangle with rounded corners. */
function roundedRect($im, float $x1, float $y1, float $x2, float $y2, float $r, $color): void
{
    imagefilledrectangle($im, (int) ($x1 + $r), (int) $y1, (int) ($x2 - $r), (int) $y2, $color);
    imagefilledrectangle($im, (int) $x1, (int) ($y1 + $r), (int) $x2, (int) ($y2 - $r), $color);
    $d = (int) ($r * 2);
    imagefilledellipse($im, (int) ($x1 + $r), (int) ($y1 + $r), $d, $d, $color);
    imagefilledellipse($im, (int) ($x2 - $r), (int) ($y1 + $r), $d, $d, $color);
    imagefilledellipse($im, (int) ($x1 + $r), (int) ($y2 - $r), $d, $d, $color);
    imagefilledellipse($im, (int) ($x2 - $r), (int) ($y2 - $r), $d, $d, $color);
}

/**
 * Draws the padlock mark: a white body with a green tick, and an open shackle.
 * $s is the overall size of the mark in pixels; ($cx,$cy) is its centre.
 */
function padlock($im, float $cx, float $cy, float $s, $bodyColor, $tickColor): void
{
    $u = $s / 100.0; // work in a 100-unit design grid

    // Shackle: half ring above the body, with two legs reaching down into it.
    $shackleCy = $cy - 14 * $u;
    $rOut = 30 * $u;
    $rIn  = 19 * $u;
    $legBottom = $shackleCy + 20 * $u;

    // Angles run 0->180 so that sin() is positive and the arc sits ABOVE the
    // body; the outer edge is traced right-to-left and the inner edge back.
    $pts = array();
    $pts[] = $cx + $rOut;
    $pts[] = $legBottom;
    for ($a = 0; $a <= 180; $a += 3) {
        $rad = deg2rad($a);
        $pts[] = $cx + $rOut * cos($rad);
        $pts[] = $shackleCy - $rOut * sin($rad);
    }
    $pts[] = $cx - $rOut;
    $pts[] = $legBottom;
    $pts[] = $cx - $rIn;
    $pts[] = $legBottom;
    for ($a = 180; $a >= 0; $a -= 3) {
        $rad = deg2rad($a);
        $pts[] = $cx + $rIn * cos($rad);
        $pts[] = $shackleCy - $rIn * sin($rad);
    }
    $pts[] = $cx + $rIn;
    $pts[] = $legBottom;

    imagefilledpolygon($im, array_map(function ($v) {
        return (int) round($v);
    }, $pts), $bodyColor);

    // Body.
    roundedRect(
        $im,
        $cx - 38 * $u,
        $cy - 2 * $u,
        $cx + 38 * $u,
        $cy + 50 * $u,
        9 * $u,
        $bodyColor
    );

    // Tick inside the body.
    $tw = 9 * $u;
    thickLine($im, $cx - 17 * $u, $cy + 24 * $u, $cx - 5 * $u, $cy + 36 * $u, $tw, $tickColor);
    thickLine($im, $cx - 5 * $u, $cy + 36 * $u, $cx + 19 * $u, $cy + 11 * $u, $tw, $tickColor);
}

/** Resamples $src down to $w x $h and writes it as a PNG. */
function output($src, int $srcW, int $srcH, int $w, int $h, string $path): void
{
    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
    imagepng($out, $path, 9);
    imagedestroy($out);
    printf("[OK] %-24s %dx%d  %s\n", basename($path), $w, $h, number_format(filesize($path)) . ' bytes');
}

// ---------------------------------------------------------------- icon ------

$iw = 256 * SS;
$icon = imagecreatetruecolor($iw, $iw);
gradient($icon, $iw, $iw, $INDIGO_DARK, $INDIGO_LIGHT);
padlock($icon, $iw / 2, $iw / 2 - 6 * SS, 150 * SS, col($icon, $WHITE), col($icon, $GREEN));

output($icon, $iw, $iw, 256, 256, $outDir . '/icon-256x256.png');
output($icon, $iw, $iw, 128, 128, $outDir . '/icon-128x128.png');
imagedestroy($icon);

// -------------------------------------------------------------- banner ------

$bw = 1544 * SS;
$bh = 500 * SS;
$banner = imagecreatetruecolor($bw, $bh);
gradient($banner, $bw, $bh, $INDIGO_DARK, $INDIGO_LIGHT);

padlock($banner, 300 * SS, 250 * SS, 200 * SS, col($banner, $WHITE), col($banner, $GREEN));

$textX = 500 * SS;
imagettftext($banner, 108 * SS, 0, $textX, 220 * SS, col($banner, $WHITE), $fontBold, 'Gatalyn');
imagettftext($banner, 62 * SS, 0, $textX, 305 * SS, col($banner, $SUBTITLE), $fontRegular, 'Login Approval');
imagettftext($banner, 34 * SS, 0, $textX, 385 * SS, col($banner, $TAGLINE), $fontRegular, 'Approve every login by email or Telegram');

output($banner, $bw, $bh, 1544, 500, $outDir . '/banner-1544x500.png');
output($banner, $bw, $bh, 772, 250, $outDir . '/banner-772x250.png');
imagedestroy($banner);
