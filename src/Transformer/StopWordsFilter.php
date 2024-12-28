<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Transformer;

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
        $path = dirname(__FILE__) . '/../../var/stopwords/' . $langIso . '.php';
        if (is_file($path)) {
            return require $path;
        }
        return [];
    }
}
