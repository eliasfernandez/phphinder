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

use PHPhinder\Exception\FileIndexException;

class FileIndex implements Index
{
    /** @var resource|false $handler */
    private $handler = false;

    /**
     * @param int<0, max> $lineLength
     */
    public function __construct(
        private readonly string $path,
        private int $lineLength = 0,
        private readonly int $schemaOptions = 0
    ) {
    }

    /**
     * @param array<string, string> $opts
     */
    public function open(array $opts = []): void
    {
        $this->handler = fopen($this->path, $opts['mode']);
    }

    public function close(): void
    {
        fclose($this->getHandler());
    }

    /**
     * @return resource
     */
    public function getHandler()
    {
        if ($this->handler === false) {
            throw new FileIndexException('File index has not been opened.');
        }
        return $this->handler;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return int<0, max>
     */
    public function getLength(): int
    {
        return $this->lineLength;
    }

    /**
     * @param int<0,max> $lineLength
     */
    public function setLength(int $lineLength): FileIndex
    {
        $this->lineLength = $lineLength;
        return $this;
    }


    public function calculateLength(): void
    {
        $this->moveTo(0);
        $this->setLength(strlen($this->getLine() ? : ''));
    }

    public function isCreated(): bool
    {
        return file_exists($this->path);
    }

    public function isEmpty(): bool
    {
        return !$this->isCreated() || filesize($this->path) === 0;
    }

    /**
     * @return int<0, max>
     */
    public function getTotalLines(): int
    {
        $handler = $this->getHandler();
        $lines = 0;
        fseek($handler, 0);
        while (!feof($handler) && fgets($handler) !== false) {
            $lines++;
        }
        return $lines;
    }

    public function moveTo(int $offset): void
    {
        fseek($this->getHandler(), $offset);
    }

    public function getLine(): string|false
    {
        return fgets($this->getHandler());
    }

    public function write(string $text): void
    {
        fwrite($this->getHandler(), $text);
    }

    public function lock(): bool
    {
        return flock($this->getHandler(), LOCK_EX);
    }

    public function unLock(): bool
    {
        return flock($this->getHandler(), LOCK_UN);
    }

    /**
     * @param int<0, max> $offset
     */
    public function split(int $offset): void
    {
        $remainingHandler = fopen($this->getTemporalPath(), 'w+');
        fseek($this->getHandler(), $offset);
        stream_copy_to_stream($this->getHandler(), $remainingHandler);
        fclose($remainingHandler);
        ftruncate($this->getHandler(), $offset);
    }

    public function join(): void
    {
        $remainingHandler = @fopen($this->getTemporalPath(), 'r');
        if (!$remainingHandler) {
            return;
        }
        stream_copy_to_stream($remainingHandler, $this->getHandler());
        fclose($remainingHandler);
        unlink($this->getTemporalPath());
    }

    public function findContaining(array $search, array $fields = ['id']): \Generator
    {
        \exec(sprintf('grep -i "%s" %s', preg_quote(current($search)), $this->path), $lines, $code);
        if ($code !== 0) {
            throw new FileIndexException('Executing grep failed.');
        }

        foreach ($lines as $line) {
            $document = \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (isset($document[key($search)]) && str_contains($document[key($search)], current($search))) {
                yield array_intersect_key($document, array_flip($fields));
            }
        }
    }

    private function getTemporalPath(): string
    {
        return sprintf('%s.2', $this->path);
    }

    public function drop(): void
    {
        unlink($this->getPath());
    }

    public function getSchemaOptions(): int
    {
        return $this->schemaOptions;
    }
}
