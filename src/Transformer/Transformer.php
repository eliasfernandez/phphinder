<?php

namespace SearchEngine\Transformer;

interface Transformer
{
    public function __construct(string $langIso = 'en', string ...$filters);
    public function apply(string $term): ?string;
}
