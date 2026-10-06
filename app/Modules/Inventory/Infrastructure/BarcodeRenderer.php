<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Satu-satunya tempat API picqer disebut. Code 128 -- BUKAN ISBT 128.
 * Dirender di dalam proses: tanpa layanan luar, tanpa panggilan jaringan.
 */
final class BarcodeRenderer
{
    /** Lebar modul terkecil dalam px; cukup untuk dipindai dari layar maupun cetakan. */
    private const int MODULE_WIDTH = 2;

    private const int HEIGHT = 60;

    public function code128Svg(string $text): string
    {
        $barcode = (new TypeCode128)->getBarcode($text);

        return (new SvgRenderer)
            // Latar putih eksplisit: pemindai butuh kontras, latar transparan
            // di atas tema gelap tidak terbaca.
            ->setBackgroundColor([255, 255, 255])
            ->render($barcode, $barcode->getWidth() * self::MODULE_WIDTH, self::HEIGHT);
    }
}
