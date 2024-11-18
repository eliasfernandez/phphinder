<?php

namespace SearchEngine\Transformer;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class StemmerTransformer implements Transformer
{
    /** @var Filter[] */
    private array $filters=[];
    private Stemmer $stemmer;

    public function __construct($langIso = 'en', string ...$filters)
    {
        foreach ($filters as $filter) {
            $this->filters []= new $filter($langIso);
        }
        $this->stemmer = StemmerFactory::create($langIso);
    }
    public function apply(string $term): ?string
    {
        foreach ($this->filters as $filter) {
            if (!$filter->allow($term)) {
                return null;
            }
        }

        return $this->stemmer->stem($term);
    }
}
