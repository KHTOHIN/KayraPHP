<?php

declare(strict_types=1);

namespace Kayra\Database\Relations;

use Kayra\Database\Model;

/**
 * One parent, one child: `User hasOne Profile`.
 *
 * Same constraints as {@see HasMany}, single result.
 */
final class HasOne extends HasOneOrMany
{
    public function getResults(): ?Model
    {
        return $this->parentKeyIsSet() ? $this->query()->first() : null;
    }

    public function match(array $models, array $results, string $relation): void
    {
        $grouped = $this->groupBy($results, $this->foreignKey);

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($this->localKey);

            $model->setRelation($relation, $grouped[$key][0] ?? null);
        }
    }
}
