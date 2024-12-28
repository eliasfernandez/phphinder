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

class StringHelper {
    public static function getShortClass(string $className, string $separator = '_'): string
    {
        $separator = strrpos($className, '\\');
        $className = substr($className,  $separator ? $separator + 1 : 0);
        $simplified = preg_split('/(?<=[a-z])(?=[A-Z])/u', $className);
        if (!is_array($simplified)) {
            throw new \InvalidArgumentException('Class name could not be parsed: ' . $className);
        }
        return strtolower(implode('_', $simplified));
    }
}
