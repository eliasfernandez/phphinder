<?php

namespace SearchEngine\Utils;

class StringHelper {
    public static function getShortClass(string $className, $separator = '_'): string
    {
        $className = substr($className, strrpos($className, '\\') + 1);
        return strtolower(implode($separator, preg_split('/(?<=[a-z])(?=[A-Z])/u', $className)));
    }
}
