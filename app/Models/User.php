<?php

declare(strict_types=1);

namespace App\Models;

use Kayra\Auth\Authenticatable;
use Kayra\Auth\Concerns\AuthenticatesUsers;
use Kayra\Database\Model;
use Kayra\Database\Relations\HasMany;

/**
 * @property int|null                $id
 * @property string                  $name
 * @property string                  $email
 * @property \DateTimeImmutable|null  $email_verified_at
 * @property \DateTimeImmutable|null  $created_at
 * @property \DateTimeImmutable|null  $updated_at
 * @property list<ApiToken>           $tokens
 */
final class User extends Model implements Authenticatable
{
    use AuthenticatesUsers;

    protected string $table = 'users';

    /**
     * Note what is absent: `password` is not fillable, so a request body can
     * never set it directly. Password changes go through the hasher.
     */
    protected array $fillable = ['name', 'email'];

    protected array $hidden = ['password', 'remember_token'];

    protected array $casts = [
        'email_verified_at' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    public function tokens(): HasMany
    {
        return $this->hasMany(ApiToken::class, 'user_id');
    }
}
