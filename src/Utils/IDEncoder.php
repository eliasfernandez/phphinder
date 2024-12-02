<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Utils;

class IDEncoder
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const BASE = 62;

    public static function encode(int $number): string {
        if ($number === 0) {
            return self::ALPHABET[0];
        }

        $result = '';
        while ($number > 0) {
            $remainder = $number % self::BASE;
            $result = self::ALPHABET[$remainder] . $result;
            $number = intdiv($number, self::BASE);
        }

        return $result;
    }

    public static function decode(string $encoded): int {
        $length = strlen($encoded);
        $number = 0;

        for ($i = 0; $i < $length; $i++) {
            $number = $number * self::BASE + strpos(self::ALPHABET, $encoded[$i]);
        }

        return $number;
    }

    // Custom comparison function
    public static function compare(string $a, string $b): int {
        $decodedA = self::decode($a);
        $decodedB = self::decode($b);
        return $decodedA <=> $decodedB;
    }
}
