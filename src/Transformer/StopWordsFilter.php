<?php

namespace SearchEngine\Transformer;


class StopWordsFilter implements Filter
{
    /**
     *  @var array<string>
     */
    private array $stopWords;
    public function __construct(string $langIso = 'en')
    {
        $this->stopWords = $this->loadStopWords($langIso);
    }

    public function allow(string $term): bool
    {
        return !in_array($term, $this->stopWords);
    }

    /**
     * @return array<string>
     */
    private function loadStopWords(string $langIso): array
    {
        if (is_file('var' . DIRECTORY_SEPARATOR . 'stopwords' . DIRECTORY_SEPARATOR . $langIso . '.php')) {
            return require 'var' . DIRECTORY_SEPARATOR . 'stopwords' . DIRECTORY_SEPARATOR . $langIso . '.php';
        }
        return [];
    }
}
