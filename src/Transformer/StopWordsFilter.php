<?php

namespace SearchEngine\Transformer;


class StopWordsFilter implements Filter
{
    private array $stopWords;
    public function __construct($langIso = 'en')
    {
        $this->stopWords = $this->loadStopWords($langIso);
    }

    public function allow(string $term): bool
    {
        return !in_array($term, $this->stopWords);
    }

    private function loadStopWords($langIso): array
    {
        if (is_file('var' . DIRECTORY_SEPARATOR . 'stopwords' . DIRECTORY_SEPARATOR . $langIso . '.php')) {
            return require 'var' . DIRECTORY_SEPARATOR . 'stopwords' . DIRECTORY_SEPARATOR . $langIso . '.php';
        }
        return [];
    }
}
