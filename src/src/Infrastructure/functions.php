<?php

declare(strict_types=1);

/**
 * @template TValue
 * @template TValues of iterable<TValue>
 *
 * @param TValues $values
 *
 * @return (TValues is non-empty-array ? non-empty-list<TValue> : list<TValue>)
 */
function toList(iterable $values): array
{
    if (is_array($values)) {
        return array_values($values);
    }

    return iterator_to_array($values, false);
}

/**
 * @template TKey of array-key
 * @template TValue
 * @template TValues of iterable<TKey, TValue>
 *
 * @param TValues $values
 *
 * @return (TValues is non-empty-array ? non-empty-array<TKey, TValue> : array<TKey, TValue>)
 */
function toArray(iterable $values): array
{
    if (is_array($values)) {
        return $values;
    }

    return iterator_to_array($values);
}

/**
 * @psalm-pure
 *
 * @throws JsonException
 */
function jsonEncode(mixed $data, int $flags = 0): string
{
    return json_encode($data, $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

/**
 * @psalm-pure
 *
 * @throws JsonException
 */
function jsonDecode(string $json, int $flags = 0): mixed
{
    return json_decode($json, true, flags: $flags | JSON_THROW_ON_ERROR);
}

/**
 * @template T of object
 *
 * @param class-string<T> $class
 *
 * @return ?ReflectionAttribute<T>
 */
function reflectionFirstAttribute(
    ReflectionClass|ReflectionFunctionAbstract|ReflectionParameter $reflection,
    string $class,
    bool $checkPrototype = true,
): ?object {
    $reflectionAttributes = $reflection->getAttributes($class, ReflectionAttribute::IS_INSTANCEOF);

    if ($reflectionAttributes !== []) {
        return $reflectionAttributes[0];
    }

    if (!($checkPrototype && $reflection instanceof ReflectionMethod)) {
        return null;
    }

    try {
        return reflectionFirstAttribute($reflection->getPrototype(), $class);
    } catch (ReflectionException) {
        return null;
    }
}

/**
 * @template T
 *
 * @param T $value
 *
 * @return Closure(): T
 */
function identity(mixed $value): Closure
{
    return static fn(): mixed => $value;
}
