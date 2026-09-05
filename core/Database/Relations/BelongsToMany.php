<?php

declare(strict_types=1);

namespace Kayra\Database\Relations;

use Kayra\Database\Model;
use Kayra\Database\ModelQuery;

/**
 * Many-to-many through a pivot table: `User belongsToMany Role`.
 *
 * The pivot key is selected alongside the related columns under an alias, so
 * that eager-loaded results can be matched back to their parents without a
 * second query against the pivot.
 */
final class BelongsToMany extends Relation
{
    /** Alias the pivot's parent key travels under on eager-loaded rows. */
    private const PIVOT_KEY = '__kayra_pivot_parent';

    public function __construct(
        Model $related,
        Model $parent,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
    ) {
        parent::__construct($related, $parent);
    }

    public function query(): ModelQuery
    {
        $relatedTable = $this->related->getTable();
        $relatedKey = $this->related->getKeyName();

        return $this->related::query()
            ->join(
                $this->pivotTable,
                $this->pivotTable . '.' . $this->relatedPivotKey,
                '=',
                $relatedTable . '.' . $relatedKey,
            )
            ->select($relatedTable . '.*')
            ->where($this->pivotTable . '.' . $this->foreignPivotKey, $this->parent->getKey());
    }

    /**
     * @return list<Model>
     */
    public function getResults(): array
    {
        if ($this->parent->getKey() === null) {
            return [];
        }

        return $this->query()->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = $this->keysFrom($models, $this->parent->getKeyName());
    }

    public function getEager(array $nested = []): array
    {
        if ($this->eagerKeys === []) {
            return [];
        }

        $relatedTable = $this->related->getTable();
        $relatedKey = $this->related->getKeyName();

        $query = $this->related::query()
            ->join(
                $this->pivotTable,
                $this->pivotTable . '.' . $this->relatedPivotKey,
                '=',
                $relatedTable . '.' . $relatedKey,
            )
            // The parent key rides along as an extra column so match() can group
            // by it without querying the pivot again.
            ->select(
                $relatedTable . '.*',
                $this->pivotTable . '.' . $this->foreignPivotKey . ' as ' . self::PIVOT_KEY,
            )
            ->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, $this->eagerKeys);

        if ($nested !== []) {
            $query->with($nested);
        }

        return $query->get();
    }

    public function match(array $models, array $results, string $relation): void
    {
        $grouped = $this->groupBy($results, self::PIVOT_KEY);
        $parentKey = $this->parent->getKeyName();

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($parentKey);

            $model->setRelation($relation, $grouped[$key] ?? []);
        }
    }

    /**
     * Attach related records to the parent.
     *
     * @param list<mixed> $ids
     */
    public function attach(array $ids): void
    {
        $parentKey = $this->parent->getKey();

        if ($parentKey === null || $ids === []) {
            return;
        }

        $rows = [];

        foreach (array_unique($ids, SORT_REGULAR) as $id) {
            $rows[] = [
                $this->foreignPivotKey => $parentKey,
                $this->relatedPivotKey => $id,
            ];
        }

        $this->parent->getConnection()->table($this->pivotTable)->insertMany($rows);
    }

    /**
     * Remove related records from the parent.
     *
     * @param list<mixed> $ids Empty removes every association.
     */
    public function detach(array $ids = []): int
    {
        $parentKey = $this->parent->getKey();

        if ($parentKey === null) {
            return 0;
        }

        $query = $this->parent->getConnection()->table($this->pivotTable)
            ->where($this->foreignPivotKey, $parentKey);

        if ($ids !== []) {
            $query->whereIn($this->relatedPivotKey, $ids);
        }

        return $query->delete();
    }

    /**
     * Make the association exactly this set.
     *
     * @param list<mixed> $ids
     */
    public function sync(array $ids): void
    {
        $this->detach();
        $this->attach($ids);
    }
}
