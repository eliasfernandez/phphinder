<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SearchEngine\Utils;

class StringHelper {
    public static function getShortClass(string $className, string $separator = '_'): string
    {
        $className = substr($className, strrpos($className, '\\') + 1);
        $simplified = preg_split('/(?<=[a-z])(?=[A-Z])/u', $className);
        if (!is_array($simplified)) {
            throw new \InvalidArgumentException('Class name could not be parsed: ' . $className);
        }
        return strtolower(implode($separator, $simplified));
    }
}
