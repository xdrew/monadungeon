<?php

declare(strict_types=1);

namespace App\Infrastructure\Uuid;

use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * @template T
 *
 * @implements \IteratorAggregate<Uuid, T>
 * @implements \ArrayAccess<string|Uuid, T>
 */
final class UuidMap implements \IteratorAggregate, \ArrayAccess, \Countable
{
    /**
     * @var array<non-empty-string, T>
     */
    private array $values = [];

    /**
     * @param array<string, T> $values
     */
    public function __construct(
        array $values = [],
    ) {
        foreach ($values as $uuid => $value) {
            if (RamseyUuid::isValid($uuid)) {
                $this->values[$uuid] = $value;

                continue;
            }

            throw new \InvalidArgumentException(sprintf('Expected valid UUID, got "%s".', $uuid));
        }
    }

    /**
     * @template TValue
     *
     * @param iterable<TValue>       $values
     * @param callable(TValue): Uuid $key
     *
     * @return self<TValue>
     */
    public static function fromValues(iterable $values, callable $key): self
    {
        /** @var UuidMap<TValue> */
        $uuidMap = new self();

        foreach ($values as $value) {
            $uuidMap[$key($value)] = $value;
        }

        return $uuidMap;
    }

    /**
     * @template TNew
     *
     * @param callable(T): TNew $mapper
     *
     * @return self<TNew>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->values));
    }

    /**
     * @return \Generator<Uuid, T>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->values as $uuid => $value) {
            yield Uuid::fromString($uuid) => $value;
        }
    }

    /**
     * @param string|Uuid $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return \array_key_exists($this->normalizeOffset($offset), $this->values);
    }

    /**
     * @param string|Uuid $offset
     *
     * @return T
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$this->normalizeOffset($offset)];
    }

    /**
     * @param string|Uuid|null $offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        \assert($offset !== null);
        $this->values[$this->normalizeOffset($offset)] = $value;
    }

    /**
     * @param string|Uuid $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$this->normalizeOffset($offset)]);
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /**
     * @return non-empty-string
     */
    private function normalizeOffset(string|Uuid $offset): string
    {
        if ($offset instanceof Uuid) {
            return $offset->toString();
        }

        if (RamseyUuid::isValid($offset)) {
            return $offset;
        }

        throw new \InvalidArgumentException(sprintf('Expected valid UUID, got "%s".', $offset));
    }
}
