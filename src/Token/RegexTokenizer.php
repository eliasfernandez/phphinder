<?php

namespace SearchEngine\Token;

class RegexTokenizer implements Tokenizer
{
    public function apply(string $text): array
    {
        $tokens = preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens ?: [];
    }
}
