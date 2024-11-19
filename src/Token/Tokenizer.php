<?php

namespace SearchEngine\Token;

interface Tokenizer
{
    public function apply(string $text): array;
}
