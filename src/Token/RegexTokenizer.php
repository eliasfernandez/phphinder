<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SearchEngine\Token;

class RegexTokenizer implements Tokenizer
{
    /**
     * @return array<string>
     */
    public function apply(string $text): array
    {
        $tokens = preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens ?: [];
    }
}
