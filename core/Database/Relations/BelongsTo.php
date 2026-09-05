<?php

declare(strict_types=1);

namespace Kayra\Database\Relations;

use Kayra\Database\Model;
use Kayra\Database\ModelQuery;

/**
 * The inverse of hasOne/hasMany: `Post belongsTo User`.
 *
 * Here it is the *parent* row that carries the foreign key, which is why the
 * key directions are the mirror image of {@see HasMany}.
 */
final class BelongsTo extends Relation
{
    public function __construct(
        Model $related,
        Model $parent,
        private readonly string $foreignKey,
        private readonly string $ownerKey,
    ) {
        parent::__construct($related, $parent);
    }

    public function query(): ModelQuery
    {
        return $this->related::query()->where($this->ownerKey, $this->parent->getAttribute($this->foreignKey));
    }

    public function getResults(): ?Model
    {
        if ($this->parent->getAttribute($this->foreignKey) === null) {
            return null;
        }

        return $this->query()->first();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = $this->keysFrom($models, $this->foreignKey);
    }

    public function getEager(array $nested = []): array
    {
        if ($this->eagerKeys === []) {
            return [];
        }

        $query = $this->related::query()->whereIn($this->ownerKey, $this->eagerKeys);

        if ($nested !== []) {
            $query->with($nested);
        }

        return $query->get();
    }

    public function match(array $models, array $results, string $relation): void
    {
        $byOwnerKey = [];

        foreach ($results as $result) {
            $byOwnerKey[(string) $result->getAttribute($this->ownerKey)] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);

            $model->setRelation(
                $relation,
                $key === null ? null : ($byOwnerKey[(string) $key] ?? null),
            );
        }
    }
}
