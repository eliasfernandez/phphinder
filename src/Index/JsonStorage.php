<?php

/**
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPhinder\Index;

use PHPhinder\Exception\StorageException;
use PHPhinder\Schema\DefaultSchema;
use PHPhinder\Schema\Schema;
use PHPhinder\Token\RegexTokenizer;
use PHPhinder\Token\Tokenizer;
use PHPhinder\Utils\StringHelper;

class JsonStorage extends AbstractStorage implements Storage
{
    private const INDEX_LINE_LENGTH_MIN = 12;
    private const DOCS_LINE_LENGTH = 256;

    /** @var FileIndex  */
    protected Index $state;

    /** @var FileIndex */
    protected Index $docs;

    /** @var array<string, FileIndex> */
    protected array $indices = [];

    public function __construct(
        string $path,
        Schema $schema = new DefaultSchema(),
        Tokenizer $tokenizer = new RegexTokenizer(),
        private readonly int $docsLineLength = self::DOCS_LINE_LENGTH
    ) {
        $this->docs = new FileIndex($path . DIRECTORY_SEPARATOR . sprintf('%s_docs.json', StringHelper::getShortClass($schema::class)), $this->docsLineLength);
        $this->state = new FileIndex($path . DIRECTORY_SEPARATOR . sprintf('%s_states.json', StringHelper::getShortClass($schema::class)), 36);

        parent::__construct($schema, $tokenizer);

        /**
         * @var string $name
         * @var int $options
         */
        foreach ($this->schemaVariables as $name => $options) {
            if ($name === self::ID) {
                throw new StorageException(sprintf('The schema provided contains a property with the reserved name %s', self::ID));
            }
            if ($options & Schema::IS_INDEXED) {
                $this->indices[$name] = new FileIndex(
                    $path . DIRECTORY_SEPARATOR . sprintf('%s_%s_index.json', StringHelper::getShortClass($schema::class), $name),
                    0,
                    $options
                );
            }
        }
    }

    public function initialize(): void
    {
        if (!$this->docs->isCreated()) {
            file_put_contents($this->docs->getPath(), "");
        }

        if (!$this->state->isCreated()) {
            file_put_contents($this->state->getPath(), "");
        }

        /** @var FileIndex $index */
        foreach ($this->indices as $index) {
            if (!$index->isCreated()) {
                file_put_contents($index->getPath(), "");
            }
        }
    }

    public function open(array $opts = ['mode' => 'r']): void
    {
        foreach ($this->indices as $index) {
            $index->open($opts);
            if (!$index->getLength()) {
                $index->calculateLength();
            }
        }
        $this->docs->open($opts);
        $this->state->open(['mode' => 'r+']);
    }


    public function count(): int
    {
        return $this->docs->getTotalLines();
    }

    /**
     * @inheritdoc
     */
    public function saveStates(array $new, array $deleted): void
    {
        $this->state->lock();
        foreach ($new as $state) {
            $this->save($this->state, [self::STATE => $state], [self::STATE => $state]);
        }

        foreach ($deleted as $state) {
            $this->remove($this->state, [self::STATE => $state]);
        }
        $this->state->unlock();
    }

    /**
     * @inheritDoc
     * @param FileIndex $index
     */
    protected function load(Index $index, array $search): array
    {
        $pattern = sprintf('{"%s":"%s"', key($search), current($search));
        $data = [];
        [$hit, $line] = $this->binarySearch(
            $index,
            $pattern
        );

        if ($hit) {
            $index->moveTo($line * $index->getLength());
            $line = $index->getLine();
            if ($line) {
                /** @var array<string, int|float|bool|string> $data*/
                $data = json_decode($line, true, JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }

    /**
     * @inheritDoc
     * @param FileIndex $index
     */
    protected function loadPrefix(Index $index, array $search): \Generator
    {
        $pattern = sprintf('{"%s":"%s', key($search), current($search));
        $results = $this->binarySearchByPrefix(
            $index,
            $pattern
        );

        foreach ($results as $line) {
            yield json_decode($line, true, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @param array<string, string> $search
     */
    protected function loadFulltext(array $search): \Generator
    {
        try {
            \exec('which grep');
            return $this->docs->findContaining($search);
        } catch (\Throwable $_) {
            throw new StorageException('Failed to load fulltext. Is `grep` executable?');
        }
    }

    /**
     * @inheritDoc
     * @param FileIndex $index
     */
    protected function loadAll(Index $index): \Generator
    {
        $index->moveTo(0);
        while ($line = $index->getLine()) {
            yield json_decode($line, true, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @inheritDoc
     * @param FileIndex $index
     */
    protected function loadByStates(Index $index, array $states): \Generator
    {
        $pattern = sprintf('"%s":(%s)}', self::STATE, implode('|', $states));
        $output = shell_exec(sprintf(
            "grep -E '%s' %s",
            $pattern,
            $index->getPath()
        ));

        if ($output) {
            $matches = explode("\n", $output);
            array_pop($matches); // remove last line
            foreach ($matches as $match) {
                yield json_decode($match, true, JSON_THROW_ON_ERROR);
            }
        }
    }

    /**
     * @inheritDoc
     * @param FileIndex $index
     */
    protected function save(Index $index, array $search, array $data, ?callable $hitCallback = null): void
    {
        $value = current($search);
        $pattern = sprintf('{"%s":%s', key($search), is_int($value) ? $value : "\"{$value}\"");
        if (!$index->lock()) {
            throw new StorageException('The handler is lock. Check if there is any other service writing on one of the files.');
        }

        // Perform a binary search to find if the term exists
        [$hit, $line] = $this->binarySearch($index, $pattern);
        $offset = $line * $index->getLength();
        $index->moveTo($offset);
        $lineData = $index->getLine();

        if ($hit) {
            if ($hitCallback) {
                $hitCallback($data, $lineData);
            }
        } else {
            $index->split($offset);
        }

        try {
            $newLine = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new StorageException('Error while trying to encode JSON: ' . $e->getMessage());
        }
        if (strlen($newLine) + 1 > $index->getLength()) {
            $offset = $this->recalculateIndexLength($newLine, $index, $line);
        }
        $index->moveTo($offset);// rewind
        $index->write(str_pad($newLine, $index->getLength() - 1) . PHP_EOL);

        $index->join();

        $index->unLock();
    }

    /**
     * @inheritDoc
     *
     * @param FileIndex $index
     */
    protected function remove(Index $index, array $search): void
    {
        $pattern = sprintf('{"%s":"%s"', key($search), current($search));
        if (!$index->lock()) {
            throw new StorageException('The handler is lock. Check if there is any other service writing on one of the files.');
        }

        [$hit, $line] = $this->binarySearch($index, $pattern);
        $offset = $line * $index->getLength();
        if (!$hit) {
            flock($index->getHandler(), LOCK_UN);
            return;
        }

        $index->split($offset + $index->getLength());

        $index->moveTo($offset); // rewind
        $index->join();

        $index->unLock();
    }

    /**
     * @return array{bool, int}
     */
    private function binarySearch(FileIndex $index, string $pattern): array
    {
        $low = 0;
        $high = $index->getTotalLines() - 1;
        while ($low <= $high) {
            // point to the middle
            $mid = (int)(($low + $high) / 2);
            $offset = ($mid) * $index->getLength();
            $index->moveTo($offset);
            $line = $index->getLine();

            // compare
            $comparison = $line !== false ? strncmp($line, $pattern, strlen($pattern)) : -1;

            if ($comparison === 0) {
                return [true, $mid]; // Term found
            } elseif ($comparison < 0) {
                $low = $mid + 1; // Search from middle to bottom
            } else {
                $high = $mid - 1; // Search from middle to top
            }
        }

        return [false, $low];
    }

    /**
     * @return array<string>
     */
    private function binarySearchByPrefix(FileIndex $index, string $prefix): array
    {
        [$hit, $mid] = $this->binarySearch($index, $prefix);
        if ($hit) {
            return $this->collectMatches($index, $mid, $prefix);
        }
        return [];
    }

    /**
     * @return array<string>
     */
    private function collectMatches(FileIndex $index, int $start, string $prefix): array
    {
        $matches = [];
        $current = $start;
        while ($current >= 0) {
            $offset = $current * $index->getLength();
            $index->moveTo($offset);
            $line = $index->getLine();

            if ($line && strncmp($line, $prefix, strlen($prefix)) === 0) {
                $matches[] = $line;
            } else {
                break;
            }
            $current--;
        }

        $current = $start + 1;
        while ($current < $index->getTotalLines()) {
            $offset = $current * $index->getLength();
            $index->moveTo($offset);
            $line = $index->getLine();

            if ($line && strncmp($line, $prefix, strlen($prefix)) === 0) {
                $matches[] = $line;
            } else {
                break;
            }
            $current++;
        }
        return $matches;
    }

    private function recalculateIndexLength(string $newLine, FileIndex $index, int $line): int
    {
        $newLength = $this->getNewLength(strlen($newLine) + 1);
        $index->moveTo(0);
        $text = stream_get_contents($index->getHandler());
        if (false === $text) {
            throw new StorageException('Could not read index length.');
        }
        $fileLines = explode(PHP_EOL, $text);
        $index->moveTo(0);
        foreach ($fileLines as $fileLine) {
            if ($fileLine === '') {
                continue;
            }

            $index->write(str_pad($fileLine, $newLength - 1) . PHP_EOL);
        }
        $index->setLength($newLength);
        return $line * $newLength;
    }

    /**
     * @return int<0, max>
     */
    private function getNewLength(int $length): int
    {
        $multiplier = 1 + ceil($length / self::INDEX_LINE_LENGTH_MIN);
        return intval(self::INDEX_LINE_LENGTH_MIN * $multiplier);
    }
}
