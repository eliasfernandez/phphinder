<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Utils;

class TypoTolerance
{
    public const ALPHABET_SIZE = 4;

    public const INDEX_LENGTH = 14;

    /**
     * @var array<int, int>
     */
    public const TYPO_THRESHOLD = [
        9 => 2,
        5 => 1,
    ];

    public static function getLevenshteinDistanceForTerm(string $term): int
    {
        $termLength = (int) mb_strlen($term, 'UTF-8');
        foreach (self::TYPO_THRESHOLD as $threshold => $distance) {
            if ($termLength >= $threshold) {
                return $distance;
            }
        }

        return 0;
    }
}
