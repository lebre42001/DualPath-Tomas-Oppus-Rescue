<?php

// Technical resizing only: keep the owner's official artwork and white background intact.
$root = dirname(__DIR__);
$source = $argv[1] ?? $root.'/web_system/resources/branding/resqmesh-emblem.png';
$canonical = $root.'/web_system/resources/branding/resqmesh-emblem.png';
if (! is_file($source)) throw new RuntimeException('Official logo source not found.');
if (! is_dir(dirname($canonical))) mkdir(dirname($canonical), 0755, true);
if (realpath($source) !== realpath($canonical) && ! copy($source, $canonical)) {
    throw new RuntimeException('Could not preserve the official logo source.');
}
$original = imagecreatefrompng($canonical);
if ($original === false) throw new RuntimeException('Official logo must be a readable PNG.');

function logoPng(GdImage $source, int $size, float $coverage = 1.0): string
{
    $canvas = imagecreatetruecolor($size, $size);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    $scale = $size * $coverage / max(imagesx($source), imagesy($source));
    $width = (int) round(imagesx($source) * $scale);
    $height = (int) round(imagesy($source) * $scale);
    imagecopyresampled($canvas, $source, (int) floor(($size - $width) / 2), (int) floor(($size - $height) / 2), 0, 0, $width, $height, imagesx($source), imagesy($source));
    ob_start(); imagepng($canvas, null, 9); $png = ob_get_clean(); imagedestroy($canvas);
    return $png;
}

$icons = $root.'/web_system/public/icons';
foreach ([64, 180, 192, 512] as $size) file_put_contents($icons.'/icon-'.$size.'.png', logoPng($original, $size));
file_put_contents($icons.'/resqmesh-logo.png', logoPng($original, 512));
// A square at 56% fits completely inside the maskable icon's central safe circle.
foreach ([192, 512] as $size) file_put_contents($icons.'/icon-maskable-'.$size.'.png', logoPng($original, $size, 0.56));

$entries = ''; $payloads = ''; $sizes = [16, 32, 48, 64, 128, 256]; $offset = 6 + 16 * count($sizes);
foreach ($sizes as $size) {
    $png = logoPng($original, $size);
    $dimension = $size === 256 ? 0 : $size;
    $entries .= pack('CCCCvvVV', $dimension, $dimension, 0, 0, 1, 32, strlen($png), $offset);
    $payloads .= $png; $offset += strlen($png);
}
file_put_contents($root.'/web_system/public/favicon.ico', pack('vvv', 0, 1, count($sizes)).$entries.$payloads);

$res = $root.'/android_app/app/src/main/res';
$densities = ['mdpi' => [48, 82, 300], 'hdpi' => [72, 123, 450], 'xhdpi' => [96, 164, 600], 'xxhdpi' => [144, 246, 900], 'xxxhdpi' => [192, 328, 1200]];
foreach ($densities as $density => [$launcher, $maskable, $splash]) {
    file_put_contents($res.'/mipmap-'.$density.'/ic_launcher.png', logoPng($original, $launcher));
    file_put_contents($res.'/mipmap-'.$density.'/ic_maskable.png', logoPng($original, $maskable, 0.56));
    file_put_contents($res.'/drawable-'.$density.'/splash.png', logoPng($original, $splash, 0.72));
}
file_put_contents($root.'/android_app/store_icon.png', logoPng($original, 512));
imagedestroy($original);
echo "Generated official ResQMesh web, favicon, PWA, Android launcher and splash assets.\n";
