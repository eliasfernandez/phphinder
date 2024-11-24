<?php

namespace SearchEngine\Index;

class FileIndex
{
    /** @var resource $handler */
    private $handler;
    public function __construct(private string $path, private int $lineLength)
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

    public function getLength(): int
    {
        return $this->lineLength;
    }

    public function setLength(int $lineLength): FileIndex
    {
        $this->lineLength = $lineLength;
        return $this;
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
}
