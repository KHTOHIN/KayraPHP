<?php

declare(strict_types=1);

namespace Kayra\Database;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Kayra\Container\Container;
use Kayra\Database\Events\CancellableModelEvent;
use Kayra\Database\Events\Created;
use Kayra\Database\Events\Creating;
use Kayra\Database\Events\Deleted;
use Kayra\Database\Events\Deleting;
use Kayra\Database\Events\ModelEvent;
use Kayra\Database\Events\Retrieved;
use Kayra\Database\Events\Saved;
use Kayra\Database\Events\Saving;
use Kayra\Database\Events\Updated;
use Kayra\Database\Events\Updating;
use Kayra\Database\Relations\BelongsTo;
use Kayra\Database\Relations\BelongsToMany;
use Kayra\Database\Relations\HasMany;
use Kayra\Database\Relations\HasOne;
use Kayra\Database\Relations\Relation;
use Kayra\Utils\Str;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

/**
 * Base class for models.
 *
 * A model is a row plus the rules for reading and writing it. Three decisions
 * are worth stating up front, because they differ from what you may expect:
 *
 *  1. **Mass assignment is closed by default.** `$guarded = ['*']` until you
 *     list `$fillable`, and passing a non-fillable key *throws* rather than
 *     being silently dropped. A silent drop looks like a working save and
 *     shows up later as missing data.
 *
 *  2. **`save()` writes only what changed.** Attributes are diffed against the
 *     values loaded from the database, so an untouched column is never included
 *     in the UPDATE — which matters when two requests write different columns
 *     of the same row.
 *
 *  3. **Relations must be eager-loaded explicitly.** Accessing an unloaded
 *     relation on a model that came from a collection is the N+1 problem, so
 *     `with()` exists and lazy access is a single deliberate query.
 *
 * @implements ArrayAccess<string, mixed>
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    /** Connection name; empty means the default. */
    protected string $connection = '';

    /** Table name; derived from the class name when empty. */
    protected string $table = '';

    protected string $primaryKey = 'id';

    protected bool $incrementing = true;

    /** Maintain created_at / updated_at. */
    protected bool $timestamps = true;

    /**
     * Attributes that may be mass-assigned.
     *
     * @var list<string>
     */
    protected array $fillable = [];

    /**
     * Attributes that may never be mass-assigned. Ignored once $fillable is set.
     *
     * @var list<string>
     */
    protected array $guarded = ['*'];

    /**
     * Attribute casts: 'int', 'float', 'bool', 'string', 'array', 'json',
     * 'datetime', 'immutable_datetime'.
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    /**
     * Attributes hidden from toArray() and JSON.
     *
     * @var list<string>
     */
    protected array $hidden = [];

    /** @var array<string, mixed> Current values. */
    protected array $attributes = [];

    /** @var array<string, mixed> Values as loaded from the database. */
    protected array $original = [];

    /** @var array<string, mixed> Loaded relations, keyed by relation name. */
    protected array $relations = [];

    protected bool $exists = false;

    /** Resolves connections. Injected once; models are not container-aware. */
    protected static ?DatabaseManager $resolver = null;

    /**
     * Final on purpose.
     *
     * Hydration and the static factories both build models with `new static()`,
     * so a subclass that changed the signature would break them in ways that
     * only show up at run time. Do initialisation in a `booted()` hook or a
     * named constructor instead.
     *
     * @param array<string, mixed> $attributes
     */
    final public function __construct(array $attributes = [])
    {
        if ($attributes !== []) {
            $this->fill($attributes);
        }
    }

    /* --------------------------------------------------------------------
     | Wiring
     * -------------------------------------------------------------------- */

    /** Shared by every model; null means lifecycle events are not fired at all. */
    private static ?EventDispatcherInterface $events = null;

    public static function setConnectionResolver(DatabaseManager $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Give every model somewhere to announce its lifecycle.
     *
     * Null -- the default -- means no events at all, not events nobody hears:
     * a model used in a script or a test does no extra work, and never has to
     * be handed a dispatcher it does not need.
     */
    public static function setEventDispatcher(?EventDispatcherInterface $events): void
    {
        self::$events = $events;
    }

    public static function getEventDispatcher(): ?EventDispatcherInterface
    {
        return self::$events;
    }

    /**
     * Run a callback with model events suppressed.
     *
     * For seeders, imports and migrations -- the places where firing a
     * listener per row is either pointless or actively wrong.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function withoutEvents(callable $callback): mixed
    {
        $previous = self::$events;
        self::$events = null;

        try {
            return $callback();
        } finally {
            self::$events = $previous;
        }
    }

    /**
     * Dispatch a lifecycle event, if anyone is listening.
     *
     * Returns false when a cancellable event was vetoed, which is what the
     * write paths below check.
     *
     * @param class-string<ModelEvent> $event
     */
    protected function fireModelEvent(string $event): bool
    {
        if (self::$events === null) {
            return true;
        }

        $dispatched = self::$events->dispatch(new $event($this));

        return !$dispatched instanceof CancellableModelEvent || !$dispatched->isCancelled();
    }

    public function getConnection(): Connection
    {
        $resolver = self::$resolver;

        if ($resolver === null) {
            // Fall back to the container so a model used outside a booted
            // application still works in a test or a script.
            $container = Container::getInstance();

            if ($container === null) {
                throw new LogicException(
                    'No database resolver is set. Call Model::setConnectionResolver(), '
                    . 'or boot the application so DatabaseServiceProvider can do it.',
                );
            }

            $resolver = $container->get(DatabaseManager::class);
            self::$resolver = $resolver;
        }

        return $resolver->connection($this->connection === '' ? null : $this->connection);
    }

    /**
     * Table name, derived from the class name when not set.
     *
     * `BlogPost` becomes `blog_posts`.
     */
    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        $short = str_contains(static::class, '\\')
            ? substr(strrchr(static::class, '\\') ?: static::class, 1)
            : static::class;

        return $this->table = Str::snake($short) . 's';
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * The column a route parameter is matched against.
     *
     * Override it to put slugs in URLs instead of ids:
     *
     *     public function getRouteKeyName(): string { return 'slug'; }
     *
     * Whatever it returns must be unique and indexed -- it is looked up on
     * every request that mentions the model.
     */
    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * The value this model contributes when a URL is generated for it.
     *
     * A string rather than mixed: this exists to be concatenated into a URL,
     * and returning mixed only moves the cast to every call site.
     */
    public function getRouteKey(): string
    {
        $value = $this->attributes[$this->getRouteKeyName()] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Mark this instance as representing a row that is already stored.
     *
     * @internal Used by {@see ModelQuery} when hydrating query results, so that
     *           save() issues an UPDATE rather than inserting a duplicate.
     */
    public function markExisting(bool $exists = true): static
    {
        $this->exists = $exists;

        // Every hydration passes through here, which makes it the one place
        // `retrieved` has to fire from. Only on the way in: markExisting(false)
        // is a model being detached, not one arriving.
        if ($exists) {
            $this->fireModelEvent(Retrieved::class);
        }

        return $this;
    }

    /* --------------------------------------------------------------------
     | Query entry points
     * -------------------------------------------------------------------- */

    /**
     * @return ModelQuery<static>
     */
    public static function query(): ModelQuery
    {
        $model = new static();

        return new ModelQuery($model->getConnection()->table($model->getTable()), $model);
    }

    /**
     * @return ModelQuery<static>
     */
    public static function where(string|\Closure $column, mixed $operator = null, mixed $value = null): ModelQuery
    {
        return func_num_args() === 2
            ? static::query()->where($column, $operator)
            : static::query()->where($column, $operator, $value);
    }

    /**
     * @param list<string>|string $relations
     * @return ModelQuery<static>
     */
    public static function with(array|string $relations): ModelQuery
    {
        return static::query()->with($relations);
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(mixed $id): ?static
    {
        $model = new static();

        return static::query()->where($model->getKeyName(), $id)->first();
    }

    public static function findOrFail(mixed $id): static
    {
        return static::find($id) ?? throw new ModelNotFoundException(
            'No ' . static::class . ' found with key ' . var_export($id, true) . '.',
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /* --------------------------------------------------------------------
     | Attributes
     * -------------------------------------------------------------------- */

    /**
     * Mass-assign attributes, honouring $fillable / $guarded.
     *
     * @param array<string, mixed> $attributes
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): static
    {
        $blocked = [];

        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } else {
                $blocked[] = $key;
            }
        }

        if ($blocked !== []) {
            // Throwing rather than dropping: a silently discarded attribute
            // looks like a successful save until the data turns out to be missing.
            throw new MassAssignmentException(
                static::class . ' cannot mass-assign: ' . implode(', ', $blocked) . '. '
                . 'Add them to $fillable, or set them individually with ->setAttribute().',
            );
        }

        return $this;
    }

    /**
     * Assign without the mass-assignment check — for trusted, internal data.
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        if ($this->fillable !== []) {
            return in_array($key, $this->fillable, true);
        }

        if (in_array('*', $this->guarded, true)) {
            return false;
        }

        return !in_array($key, $this->guarded, true);
    }

    public function getAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->castGet($key, $this->attributes[$key]);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // A method of the same name defines a relation; resolve it once.
        if ($this->hasRelationMethod($key)) {
            return $this->relations[$key] = $this->resolveRelation($key)->getResults();
        }

        return null;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->castSet($key, $value);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Raw values as loaded from the database, before any casting.
     *
     * @param array<string, mixed> $attributes
     */
    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->original = $attributes;
        }

        return $this;
    }

    /**
     * Attributes whose value differs from what was loaded.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    /* --------------------------------------------------------------------
     | Casting
     * -------------------------------------------------------------------- */

    private function castGet(string $key, mixed $value): mixed
    {
        if ($value === null || !isset($this->casts[$key])) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string'          => (string) $value,
            'array', 'json'   => is_string($value) ? (json_decode($value, true) ?? []) : $value,
            'datetime', 'immutable_datetime' => $this->toDateTime($value),
            default           => $value,
        };
    }

    private function castSet(string $key, mixed $value): mixed
    {
        if ($value === null || !isset($this->casts[$key])) {
            return $value;
        }

        // Stored form: what the database column actually holds.
        return match ($this->casts[$key]) {
            'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            'bool', 'boolean' => $value ? 1 : 0,
            'datetime', 'immutable_datetime' => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value,
            default => $value,
        };
    }

    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /* --------------------------------------------------------------------
     | Persistence
     * -------------------------------------------------------------------- */

    public function save(): bool
    {
        // `saving` wraps both paths, so a listener that validates or stamps a
        // column does not have to be registered twice.
        if (!$this->fireModelEvent(Saving::class)) {
            return false;
        }

        $saved = $this->exists ? $this->performUpdate() : $this->performInsert();

        if ($saved) {
            $this->fireModelEvent(Saved::class);
        }

        return $saved;
    }

    private function performInsert(): bool
    {
        // Fired before the timestamps are stamped, so a listener can set
        // created_at itself and have that value survive.
        if (!$this->fireModelEvent(Creating::class)) {
            return false;
        }

        if ($this->timestamps) {
            $now = gmdate('Y-m-d H:i:s');
            $this->attributes['created_at'] ??= $now;
            $this->attributes['updated_at'] ??= $now;
        }

        $query = $this->getConnection()->table($this->getTable());

        if ($this->incrementing) {
            $id = $query->insertGetId($this->attributes, $this->primaryKey);
            $this->attributes[$this->primaryKey] = is_numeric($id) ? (int) $id : $id;
        } else {
            $query->insert($this->attributes);
        }

        $this->exists = true;
        $this->original = $this->attributes;

        // After the key exists: a listener can use it.
        $this->fireModelEvent(Created::class);

        return true;
    }

    private function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        // Nothing changed: an UPDATE with no SET is both pointless and invalid.
        // No event either -- "updating" should mean something is being updated.
        if ($dirty === []) {
            return true;
        }

        if (!$this->fireModelEvent(Updating::class)) {
            return false;
        }

        // A listener may have changed something, so ask again.
        $dirty = $this->getDirty();

        if ($this->timestamps) {
            $dirty['updated_at'] = $this->attributes['updated_at'] = gmdate('Y-m-d H:i:s');
        }

        $key = $this->getKey();

        if ($key === null) {
            throw new RuntimeException(
                'Cannot update ' . static::class . ' without a value for ' . $this->primaryKey . '.',
            );
        }

        $this->getConnection()->table($this->getTable())
            ->where($this->primaryKey, $key)
            ->update($dirty);

        $this->original = $this->attributes;

        $this->fireModelEvent(Updated::class);

        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $key = $this->getKey();

        if ($key === null) {
            throw new RuntimeException('Cannot delete a model without a primary key value.');
        }

        if (!$this->fireModelEvent(Deleting::class)) {
            return false;
        }

        $this->getConnection()->table($this->getTable())
            ->where($this->primaryKey, $key)
            ->delete();

        $this->exists = false;

        // The attributes are still populated here on purpose: a listener that
        // needs to log what was removed has nowhere else to read it from.
        $this->fireModelEvent(Deleted::class);

        return true;
    }

    /**
     * Re-read this row from the database.
     */
    public function refresh(): static
    {
        $fresh = static::find($this->getKey());

        if ($fresh !== null) {
            $this->setRawAttributes($fresh->getAttributes(), sync: true);
            $this->relations = [];
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);

        return $this->save();
    }

    /* --------------------------------------------------------------------
     | Relations
     * -------------------------------------------------------------------- */

    /**
     * @param class-string<Model> $related
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = new $related();

        return new HasMany(
            $instance,
            $this,
            $foreignKey ?? $this->foreignKeyName(),
            $localKey ?? $this->primaryKey,
        );
    }

    /**
     * @param class-string<Model> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = new $related();

        return new HasOne(
            $instance,
            $this,
            $foreignKey ?? $this->foreignKeyName(),
            $localKey ?? $this->primaryKey,
        );
    }

    /**
     * @param class-string<Model> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = new $related();

        return new BelongsTo(
            $instance,
            $this,
            $foreignKey ?? $instance->foreignKeyName(),
            $ownerKey ?? $instance->getKeyName(),
        );
    }

    /**
     * @param class-string<Model> $related
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
    ): BelongsToMany {
        $instance = new $related();

        // Conventional pivot name: both tables, singularised, alphabetical.
        if ($pivotTable === null) {
            $names = [Str::singular($this->getTable()), Str::singular($instance->getTable())];
            sort($names);
            $pivotTable = implode('_', $names);
        }

        return new BelongsToMany(
            $instance,
            $this,
            $pivotTable,
            $foreignPivotKey ?? $this->foreignKeyName(),
            $relatedPivotKey ?? $instance->foreignKeyName(),
        );
    }

    /**
     * The conventional foreign-key column for this model: `user_id`.
     */
    public function foreignKeyName(): string
    {
        $short = str_contains(static::class, '\\')
            ? substr(strrchr(static::class, '\\') ?: static::class, 1)
            : static::class;

        return Str::snake($short) . '_' . $this->primaryKey;
    }

    public function hasRelationMethod(string $name): bool
    {
        // Only methods declared on the subclass count, so an attribute named
        // "table" cannot accidentally resolve to getTable().
        return method_exists($this, $name) && !method_exists(self::class, $name);
    }

    public function resolveRelation(string $name): Relation
    {
        /** @var mixed $relation */
        $relation = $this->{$name}();

        if (!$relation instanceof Relation) {
            throw new LogicException(
                static::class . '::' . $name . '() must return a relation.',
            );
        }

        return $relation;
    }

    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /* --------------------------------------------------------------------
     | Serialisation
     * -------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->attributes as $key => $ignored) {
            if (in_array($key, $this->hidden, true)) {
                continue;
            }

            $value = $this->getAttribute($key);

            $result[$key] = $value instanceof DateTimeInterface
                ? $value->format(DateTimeInterface::ATOM)
                : $value;
        }

        foreach ($this->relations as $name => $related) {
            if (in_array($name, $this->hidden, true)) {
                continue;
            }

            $result[$name] = match (true) {
                $related instanceof self => $related->toArray(),
                is_array($related)       => array_map(
                    static fn (mixed $item): mixed => $item instanceof self ? $item->toArray() : $item,
                    $related,
                ),
                default => $related,
            };
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /* --------------------------------------------------------------------
     | Magic access
     * -------------------------------------------------------------------- */

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key])
            || isset($this->relations[$key])
            || $this->hasRelationMethod($key);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset((string) $offset);
    }
}
