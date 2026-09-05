<?php

declare(strict_types=1);

namespace Kayra\Database\Relations;

use Kayra\Database\Model;
use Kayra\Database\ModelQuery;

/**
 * Shared behaviour for relations where the *child* row carries the foreign key.
 *
 * `HasOne` and `HasMany` constrain and fetch identically; they differ only in
 * cardinality — whether one model or a list comes back. That difference is why
 * they are siblings here rather than one extending the other: a subclass cannot
 * narrow an inherited `array` return type to `?Model`.
 */
abstract class HasOneOrMany extends Relation
{
    public function __construct(
        Model $related,
        Model $parent,
        protected readonly string $foreignKey,
        protected readonly string $localKey,
    ) {
        parent::__construct($related, $parent);
    }

    public function query(): ModelQuery
    {
        return $this->related::query()
            ->where($this->foreignKey, $this->parent->getAttribute($this->localKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = $this->keysFrom($models, $this->localKey);
    }

    public function getEager(array $nested = []): array
    {
        if ($this->eagerKeys === []) {
            return [];
        }

        $query = $this->related::query()->whereIn($this->foreignKey, $this->eagerKeys);

        if ($nested !== []) {
            $query->with($nested);
        }

        return $query->get();
    }

    /**
     * Whether the parent has a usable key at all.
     */
    protected function parentKeyIsSet(): bool
    {
        return $this->parent->getAttribute($this->localKey) !== null;
    }
}
