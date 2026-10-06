<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Nomor unit darah: BL-{FAC}-{YYMMDD}-{SEQ}{RND}{CHK}, tetap 22 karakter,
 * mis. BL-JKT1-260925-0007A9K.
 *
 * - FAC: 4 karakter dari kode fasilitas, dinormalkan ke alfabet Crockford.
 * - SEQ: pencacah atomik per (FAC, tanggal) -- sumber keunikan.
 * - RND: 2 karakter acak Crockford -- nomor berikutnya tidak bisa ditebak.
 * - CHK: cek digit ISO 7064 MOD 37,36 atas seluruh karakter alfanumerik.
 *
 * Code 128, BUKAN ISBT 128. Sengaja tanpa satu pun `use`: layer DomInventory
 * tidak boleh bergantung ke apa pun.
 */
final class UnitNumberFormat
{
    /** Crockford Base32: tanpa I, L, O, U supaya tidak salah dengar/salah baca. */
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const string PATTERN = '/^BL-[0-9A-HJKMNP-TV-Z]{4}-\d{6}-\d{4}[0-9A-HJKMNP-TV-Z]{2}[0-9A-Z]$/';

    public const int LENGTH = 22;

    public const int MAX_SEQUENCE = 9999;

    private const string ISO7064_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Prefix fasilitas: alfanumerik pertama kode fasilitas, huruf yang rawan
     * tertukar dipetakan seperti Crockford (I/L -> 1, O -> 0, U -> V), lalu
     * dipotong/dilengkapi '0' sampai 4 karakter. Dua fasilitas boleh berbagi
     * prefix: keunikan dijaga pencacah per prefix, bukan oleh prefix-nya.
     */
    public static function facilityPrefix(string $facilityCode): string
    {
        $normalized = strtr(
            (string) preg_replace('/[^0-9A-Z]/', '', strtoupper($facilityCode)),
            ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V'],
        );

        return str_pad(substr($normalized, 0, 4), 4, '0');
    }

    public static function period(\DateTimeInterface $at): string
    {
        return $at->format('ymd');
    }

    public static function compose(string $prefix, string $period, int $sequence, string $random): string
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new \InvalidArgumentException("Sequence {$sequence} is outside 1..".self::MAX_SEQUENCE.'.');
        }

        $withoutCheck = sprintf('BL-%s-%s-%04d%s', $prefix, $period, $sequence, $random);

        return $withoutCheck.self::checkCharacter($withoutCheck);
    }

    public static function isValid(string $number): bool
    {
        if (preg_match(self::PATTERN, $number) !== 1) {
            return false;
        }

        return self::checkCharacter(substr($number, 0, -1)) === substr($number, -1);
    }

    /** ISO 7064 MOD 37,36 (sistem hibrida) atas karakter alfanumeriknya saja. */
    public static function checkCharacter(string $payload): string
    {
        $modulus = 36;
        $product = $modulus;

        foreach (str_split((string) preg_replace('/[^0-9A-Z]/', '', $payload)) as $character) {
            $sum = ($product + strpos(self::ISO7064_CHARSET, $character)) % $modulus;
            $product = (($sum === 0 ? $modulus : $sum) * 2) % ($modulus + 1);
        }

        return self::ISO7064_CHARSET[($modulus + 1 - $product) % $modulus];
    }
}
