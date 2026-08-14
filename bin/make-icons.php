<?php

declare(strict_types=1);

/**
 * Gera os ícones PNG do PWA (gradiente com o monograma "PG").
 *   php bin/make-icons.php
 */

$dir = __DIR__ . '/../public/assets/icons';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

/** Desenha um ícone quadrado com gradiente diagonal e o texto "PG". */
function makeIcon(int $size, string $path, bool $maskable = false): void
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Gradiente diagonal Publish: azul #2563eb → azul claro #38bdf8.
    [$r1, $g1, $b1] = [0x25, 0x63, 0xeb];
    [$r2, $g2, $b2] = [0x38, 0xbd, 0xf8];
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $t = (($x + $y) / ($size * 2));
            $r = (int) ($r1 + ($r2 - $r1) * $t);
            $g = (int) ($g1 + ($g2 - $g1) * $t);
            $b = (int) ($b1 + ($b2 - $b1) * $t);
            $color = imagecolorallocate($img, $r, $g, $b);
            imagesetpixel($img, $x, $y, $color);
        }
    }

    // Cantos arredondados (exceto maskable, que precisa preencher a área de segurança).
    if (!$maskable) {
        $radius = (int) ($size * 0.22);
        $bg = imagecolorallocatealpha($img, 0, 0, 0, 127);
        roundCorners($img, $size, $radius, $bg);
    }

    // Monograma "PG".
    $white = imagecolorallocate($img, 255, 255, 255);
    $font = 5; // fonte embutida
    $text = 'PG';
    // Escala manual desenhando um bloco grande de texto com TTF se disponível; senão usa imagestring ampliado.
    $fontFile = findFont();
    if ($fontFile !== null) {
        $fontSize = (int) ($size * 0.34);
        $box = imagettfbbox($fontSize, 0, $fontFile, $text);
        $tw = $box[2] - $box[0];
        $th = $box[1] - $box[7];
        $x = (int) (($size - $tw) / 2 - $box[0]);
        $y = (int) (($size - $th) / 2 + $th - $box[1]);
        imagettftext($img, $fontSize, 0, $x, $y, $white, $fontFile, $text);
    } else {
        // Fallback: caractere da fonte interna ampliado via cópia redimensionada.
        $tmp = imagecreatetruecolor(40, 20);
        imagesavealpha($tmp, true);
        $trans = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $trans);
        imagestring($tmp, $font, 12, 2, $text, $white);
        $scale = (int) ($size * 0.5);
        imagecopyresampled($img, $tmp, (int) (($size - $scale) / 2), (int) (($size - $scale / 2) / 2), 0, 0, $scale, (int) ($scale / 2), 40, 20);
        imagedestroy($tmp);
    }

    imagepng($img, $path);
    imagedestroy($img);
    echo "✓ {$path}\n";
}

function roundCorners($img, int $size, int $radius, int $transparent): void
{
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $cx = null;
            $cy = null;
            if ($x < $radius && $y < $radius) { $cx = $radius; $cy = $radius; }
            elseif ($x >= $size - $radius && $y < $radius) { $cx = $size - $radius; $cy = $radius; }
            elseif ($x < $radius && $y >= $size - $radius) { $cx = $radius; $cy = $size - $radius; }
            elseif ($x >= $size - $radius && $y >= $size - $radius) { $cx = $size - $radius; $cy = $size - $radius; }
            if ($cx !== null) {
                $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2);
                if ($d > $radius) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
    }
}

function findFont(): ?string
{
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

makeIcon(192, $dir . '/icon-192.png');
makeIcon(512, $dir . '/icon-512.png');
makeIcon(512, $dir . '/icon-maskable-512.png', true);

echo "✓ Ícones gerados.\n";
