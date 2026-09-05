<?php

declare(strict_types=1);

namespace Kayra\Database\Concerns;

use Kayra\Database\ModelQuery;

/**
 * Marks rows as deleted instead of removing them.
 *
 * The model must have a nullable `deleted_at` column. Queries built through
 * {@see query()} exclude trashed rows by default; `withTrashed()` and
 * `onlyTrashed()` opt back in.
 *
 * The default is deliberate: a soft-deleting model whose default query still
 * returned deleted rows would silently leak them into every listing.
 */
trait SoftDeletes
{
    public function getDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT')
            ? (string) constant(static::class . '::DELETED_AT')
            : 'deleted_at';
    }

    /**
     * Live rows only.
     *
     * @return ModelQuery<static>
     */
    public static function query(): ModelQuery
    {
        $model = new static();

        return (new ModelQuery($model->getConnection()->table($model->getTable()), $model))
            ->whereNull($model->getDeletedAtColumn());
    }

    /**
     * Live and trashed rows.
     *
     * @return ModelQuery<static>
     */
    public static function withTrashed(): ModelQuery
    {
        $model = new static();

        return new ModelQuery($model->getConnection()->table($model->getTable()), $model);
    }

    /**
     * Trashed rows only.
     *
     * @return ModelQuery<static>
     */
    public static function onlyTrashed(): ModelQuery
    {
        $model = new static();

        return (new ModelQuery($model->getConnection()->table($model->getTable()), $model))
            ->whereNotNull($model->getDeletedAtColumn());
    }

    /**
     * Mark this row deleted rather than removing it.
     */
    public function delete(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $column = $this->getDeletedAtColumn();

        $this->setAttribute($column, gmdate('Y-m-d H:i:s'));

        $this->getConnection()->table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update([$column => $this->getAttributes()[$column]]);

        return true;
    }

    /**
     * Remove the row for real.
     */
    public function forceDelete(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $this->getConnection()->table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->delete();

        $this->markExisting(false);

        return true;
    }

    public function restore(): bool
    {
        $column = $this->getDeletedAtColumn();

        $this->setAttribute($column, null);

        $this->getConnection()->table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update([$column => null]);

        return true;
    }

    public function trashed(): bool
    {
        return $this->getAttributes()[$this->getDeletedAtColumn()] !== null;
    }
}
