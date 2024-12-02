<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SearchEngine\Transformer;

interface Transformer
{
    public function __construct(string $langIso = 'en', string ...$filters);
    public function apply(string $term): ?string;
}
