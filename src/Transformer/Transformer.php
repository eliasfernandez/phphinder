<?php

namespace SearchEngine\Transformer;

interface Transformer
{
    public function __construct($langIso = 'en', string ...$filters);
    public function apply(string $term): ?string;
}
