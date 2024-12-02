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

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class SymbolTransformer implements Transformer
{
    use TransformerTrait;

    /** @var array<Filter> */
    private array $filters=[];

    public function __construct(string $langIso = 'en', string ...$filters)
    {
        $this->loadFilters($filters, $langIso);
    }
    public function apply(string $term): ?string
    {
        foreach ($this->filters as $filter) {
            if (!$filter->allow($term)) {
                return null;
            }
        }

        return preg_replace('/[^a-zA-Z0-9]+/', '', $term);
    }
}
