<?php

declare(strict_types=1);

namespace Kayra\Database\Relations;

use Kayra\Database\Model;
use Kayra\Database\ModelQuery;

/**
 * Base class for relations.
 *
 * A relation answers two different questions and it is worth keeping them
 * distinct:
 *
 *   getResults()  — "what is related to *this one* model?"  (one query, lazy)
 *   getEager()    — "what is related to *all of these* models?"  (one query for
 *                   the whole set, then matched back onto them)
 *
 * The second is what makes eager loading a fixed two queries instead of N+1.
 */
abstract class Relation
{
    /** @var list<mixed> Parent keys collected by addEagerConstraints(). */
    protected array $eagerKeys = [];

    public function __construct(
        protected readonly Model $related,
        protected readonly Model $parent,
    ) {
    }

    /**
     * The related model's query, constrained to this relation.
     */
    abstract public function query(): ModelQuery;

    /**
     * Results for the single parent this relation was built from.
     */
    abstract public function getResults(): mixed;

    /**
     * Collect the keys needed to fetch results for a whole set of parents.
     *
     * @param list<Model> $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Fetch results for every parent collected so far.
     *
     * @param list<string> $nested Further relations to load on the results.
     * @return list<Model>
     */
    abstract public function getEager(array $nested = []): array;

    /**
     * Attach the fetched results to the parents they belong to.
     *
     * @param list<Model> $models
     * @param list<Model> $results
     */
    abstract public function match(array $models, array $results, string $relation): void;

    /**
     * Distinct, non-null values of a key across a set of models.
     *
     * @param list<Model> $models
     * @return list<mixed>
     */
    protected function keysFrom(array $models, string $key): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->getAttribute($key);

            if ($value !== null) {
                $keys[] = $value;
            }
        }

        // array_unique compares as strings, which is what we want: an int id
        // and its string form address the same row.
        return array_values(array_unique($keys, SORT_REGULAR));
    }

    /**
     * Group results by the value of a foreign key.
     *
     * @param list<Model> $results
     * @return array<string, list<Model>>
     */
    protected function groupBy(array $results, string $key): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $grouped[(string) $result->getAttribute($key)][] = $result;
        }

        return $grouped;
    }
}
