<?php

/*
 * This file is part of the PHPhind package.
 *
 * (c) Elías Fernández Velázquez
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */ 

namespace PHPhinder\Index;

class FileIndex
{
    /** @var resource $handler */
    private $handler;
    public function __construct(private readonly string $path, private int $lineLength = 0)
    {
    }

    public function open(array $opts = []): void
    {
        $this->handler = fopen($this->path, $opts['mode']);
    }

    public function close(): void
    {
        fclose($this->handler);
    }

    /**
     * @return resource
     */
    public function getHandler()
    {
        return $this->handler;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getLength(): ?int
    {
        return $this->lineLength;
    }

    public function setLength(int $lineLength): FileIndex
    {
        $this->lineLength = $lineLength;
        return $this;
    }


    public function calculateLength(): void
    {
        $this->moveTo(0);
        $this->setLength(strlen($this->getLine()));
    }

    public function isCreated(): bool
    {
        return file_exists($this->path);
    }

    public function getTotalLines(): int
    {
        $lines = 0;
        fseek($this->handler, 0);
        while (!feof($this->handler) && fgets($this->handler) !== false) {
            $lines++;
        }
        return $lines;
    }

    public function moveTo(int $offset): void
    {
        fseek($this->handler, $offset);
    }

    public function getLine(): string|false
    {
        return fgets($this->handler);
    }

    public function write(string $text): void
    {
        fwrite($this->handler, $text);
    }
}
