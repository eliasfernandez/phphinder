<?php

namespace SearchEngine\Token;

interface Tokenizer
{
    /**
     * @return array<string>
     */
    public function apply(string $text): array;
}
