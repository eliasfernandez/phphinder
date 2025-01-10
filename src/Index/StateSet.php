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

use Toflar\StateSetIndex\StateSet\StateSetInterface;

/**
 * This file is partially based on the implementation on loupe/loupe of the
 * library toflar/state-set-index
 */
class StateSet implements StateSetInterface
{
    /**
     * @var array<int, bool>
     */
    private ?array $states = null;


    /**
     * @var array<int>
     */
    private array $new = [];

    /**
     * @var array<int, bool>
     */
    private array $deleted = [];

    public function __construct(private Storage $storage)
    {
    }


    public function add(int $state): void
    {
        $this->initialize();
        if (!$this->has($state)) {
            $this->new[] = $state;
            $this->states[$state] = true;
        }
    }

    public function all(): array
    {
        $this->initialize();
        return array_keys($this->states);
    }

    public function has(int $state): bool
    {
        $this->initialize();
        return isset($this->states[$state]);
    }

    public function remove(int $state): void
    {
        $this->initialize();
        if ($this->has($state)) {
            $this->deleted[] = $state;
            unset($this->states[$state]);
        }
    }

    public function persist(): void
    {
        $this->initialize();
        $this->storage->saveStates($this->new, $this->deleted);
        $this->new = [];
        $this->deleted = [];
    }

    private function initialize(): void
    {
        if (null === $this->states) {
            $this->states = $this->loadFromStorage();
        }
    }

    /**
     * @return array<int, bool>
     */
    private function loadFromStorage(): array
    {
        $storage = [];
        foreach ($this->storage->getStates() as $state) {
            $storage[$state] = true;
        }

        return $storage;
    }
}
