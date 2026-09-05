<?php

declare(strict_types=1);

namespace Kayra\Database\Relations;

/**
 * One parent, many children: `User hasMany Post`.
 *
 * The child table carries the foreign key.
 */
final class HasMany extends HasOneOrMany
{
    /**
     * @return list<\Kayra\Database\Model>
     */
    public function getResults(): array
    {
        return $this->parentKeyIsSet() ? $this->query()->get() : [];
    }

    public function match(array $models, array $results, string $relation): void
    {
        $grouped = $this->groupBy($results, $this->foreignKey);

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($this->localKey);

            // Always set the relation, even when empty: leaving it unset would
            // fall through to a lazy query and reintroduce N+1.
            $model->setRelation($relation, $grouped[$key] ?? []);
        }
    }
}
