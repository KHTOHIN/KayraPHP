<?php

declare(strict_types=1);

namespace App\Models;

use Kayra\Database\Model;
use Kayra\Database\Relations\BelongsTo;

/**
 * A personal access token.
 *
 * `token` holds a SHA-256 hash, never the plaintext: the plaintext is shown to
 * the user once, at creation, and is unrecoverable afterwards.
 */
/**
 * @property int|null                $id
 * @property int                     $user_id
 * @property string                  $name
 * @property \DateTimeImmutable|null  $expires_at
 * @property User|null                $user
 */
final class ApiToken extends Model
{
    protected string $table = 'api_tokens';

    protected array $fillable = ['user_id', 'name', 'token', 'expires_at'];

    protected array $hidden = ['token'];

    protected array $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
