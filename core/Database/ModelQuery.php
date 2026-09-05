<?php

declare(strict_types=1);

namespace Kayra\Database;

use Closure;
use Kayra\Database\Relations\Relation;

/**
 * A query that returns models instead of arrays.
 *
 * Wraps {@see QueryBuilder} rather than extending it: the builder's job is to
 * produce SQL, and hydration is a separate concern. Unknown methods are
 * forwarded through __call, so the whole builder API remains available and
 * returns `$this` for chaining.
 *
 * The @method list below is not decoration — it is what makes the forwarded
 * API visible to static analysis and to editors. Without it every forwarded
 * call is an unknown method.
 *
 * @template TModel of Model
 *
 * @method $this select(string ...$columns)
 * @method $this addSelect(string ...$columns)
 * @method $this distinct()
 * @method $this whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false)
 * @method $this whereNotIn(string $column, array $values, string $boolean = 'AND')
 * @method $this whereNull(string $column, string $boolean = 'AND', bool $not = false)
 * @method $this whereNotNull(string $column, string $boolean = 'AND')
 * @method $this whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND')
 * @method $this whereColumn(string $first, string $operator, string $second, string $boolean = 'AND')
 * @method $this join(string $table, string $first, string $operator, string $second, string $type = 'INNER')
 * @method $this leftJoin(string $table, string $first, string $operator, string $second)
 * @method $this groupBy(string ...$columns)
 * @method $this having(string $column, string $operator, mixed $value)
 * @method $this orderBy(string $column, string $direction = 'ASC')
 * @method $this latest(string $column = 'created_at')
 * @method $this limit(int $limit)
 * @method $this offset(int $offset)
 * @method $this forPage(int $page, int $perPage = 15)
 */
final class ModelQuery
{
    /** @var list<string> Relations to eager-load. */
    private array $eagerLoad = [];

    /**
     * @param TModel $model
     */
    public function __construct(
        private readonly QueryBuilder $query,
        private readonly Model $model,
    ) {
    }

    public function toBase(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Load these relations in one query each, rather than one per row.
     *
     * This is the whole point of eager loading: fetching 100 posts and touching
     * `$post->author` produces 101 queries without it, and 2 with it.
     *
     * @param list<string>|string $relations
     */
    public function with(array|string $relations): self
    {
        foreach ((array) $relations as $relation) {
            if (!in_array($relation, $this->eagerLoad, true)) {
                $this->eagerLoad[] = $relation;
            }
        }

        return $this;
    }

    /* --------------------------------------------------------------------
     | Execution
     * -------------------------------------------------------------------- */

    /**
     * @return list<TModel>
     */
    public function get(): array
    {
        $models = array_map($this->hydrate(...), $this->query->get());

        if ($models !== [] && $this->eagerLoad !== []) {
            $this->loadRelations($models);
        }

        return $models;
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        $model = $this->hydrate($row);

        if ($this->eagerLoad !== []) {
            $this->loadRelations([$model]);
        }

        return $model;
    }

    /**
     * @return TModel
     */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException(
            'No ' . $this->model::class . ' matched the query.',
        );
    }

    /**
     * Stream models without materialising the whole result.
     *
     * Eager loading is unavailable here by definition: it needs the full set of
     * parent keys up front, which is exactly what streaming avoids.
     *
     * @return \Generator<int, TModel>
     */
    public function cursor(): \Generator
    {
        foreach ($this->query->cursor() as $row) {
            yield $this->hydrate($row);
        }
    }

    /**
     * @param callable(list<TModel>, int): (bool|null) $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        $this->query->chunk($size, function (array $rows, int $page) use ($callback): bool|null {
            $models = array_map($this->hydrate(...), $rows);

            if ($this->eagerLoad !== []) {
                $this->loadRelations($models);
            }

            return $callback($models, $page);
        });
    }

    public function count(string $column = '*'): int
    {
        return $this->query->count($column);
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }

    public function delete(): int
    {
        return $this->query->delete();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return $this->query->update($values);
    }

    /**
     * Every value of one column, in result order.
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $result = [];

        foreach ($this->query->get() as $row) {
            $result[] = $row[$column] ?? null;
        }

        return $result;
    }

    /* --------------------------------------------------------------------
     | Hydration
     * -------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $row
     * @return TModel
     */
    private function hydrate(array $row): Model
    {
        $class = $this->model::class;

        /** @var TModel $model */
        $model = new $class();

        // Marked as existing so save() issues an UPDATE rather than inserting
        // a duplicate row.
        return $model->setRawAttributes($row, sync: true)->markExisting();
    }

    /**
     * Run one query per eager-loaded relation and attach the results.
     *
     * @param list<TModel> $models
     */
    private function loadRelations(array $models): void
    {
        foreach ($this->eagerLoad as $name) {
            // Nested paths ("author.company") load the first segment here; the
            // remainder is handed to the relation's own query.
            [$segment, $nested] = array_pad(explode('.', $name, 2), 2, null);

            if (!$this->model->hasRelationMethod($segment)) {
                throw new \LogicException(
                    'Cannot eager-load [' . $segment . ']: ' . $this->model::class
                    . ' has no such relation method.',
                );
            }

            $relation = $this->model->resolveRelation($segment);

            $relation->addEagerConstraints($models);

            $results = $nested === null
                ? $relation->getEager()
                : $relation->getEager([$nested]);

            $relation->match($models, $results, $segment);
        }
    }

    /* --------------------------------------------------------------------
     | Builder passthrough
     * -------------------------------------------------------------------- */

    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure || func_num_args() === 2) {
            $this->query->where($column, $operator);
        } else {
            $this->query->where($column, $operator, $value);
        }

        return $this;
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure || func_num_args() === 2) {
            $this->query->orWhere($column, $operator);
        } else {
            $this->query->orWhere($column, $operator, $value);
        }

        return $this;
    }

    /**
     * Forward everything else to the underlying builder.
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): self
    {
        if (!method_exists($this->query, $method)) {
            throw new \BadMethodCallException(
                'Neither ' . self::class . ' nor ' . QueryBuilder::class . ' has a method [' . $method . '].',
            );
        }

        $this->query->{$method}(...$arguments);

        return $this;
    }
}
