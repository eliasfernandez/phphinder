<?php

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
