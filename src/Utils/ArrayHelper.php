<?php

namespace SearchEngine\Utils;

class ArrayHelper
{
    public static function arrayMergeRecursivePreserveKeys(array $array1, array $array2): array
    {
        $merged = $array1;
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                // If both values are arrays, recursively merge them
                $merged[$key] = self::arrayMergeRecursivePreserveKeys($merged[$key], $value);
            } elseif (is_int($key) && isset($merged[$key])) {
                // If the key is numeric and already exists, create an array of values
                $merged[$key] = array_merge((array) $merged[$key], (array) $value);
            } else {
                // Otherwise, just set the value
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
