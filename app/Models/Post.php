<?php

declare(strict_types=1);

namespace App\Models;

use Kayra\Database\Model;
use Kayra\Database\Relations\BelongsTo;

/**
 * @property int|null                $id
 * @property int                     $user_id
 * @property string                  $title
 * @property string                  $body
 * @property \DateTimeImmutable|null $created_at
 * @property \DateTimeImmutable|null $updated_at
 * @property User|null               $author
 */
final class Post extends Model
{
    protected string $table = 'posts';

    /**
     * user_id is absent on purpose: ownership is set by the controller from
     * the authenticated user, never from the request body.
     */
    protected array $fillable = ['title', 'body'];

    protected array $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
