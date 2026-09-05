<?php

declare(strict_types=1);

use Kayra\Database\Blueprint;
use Kayra\Database\Migration;

/**
 * The tables the auth layer expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            // The hash, never the password. bcrypt is 60 characters; argon2id
            // is longer, so the column is sized for the larger of the two.
            $table->string('password', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->unique('email');
        });

        $this->schema()->create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('name');
            // A SHA-256 hex digest of the token. The plaintext is shown once at
            // creation and is unrecoverable afterwards.
            $table->string('token', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique('token');
            $table->index('user_id');
            $table->foreign('user_id', 'users', 'id', 'CASCADE');
        });

        $this->schema()->create('password_resets', function (Blueprint $table): void {
            $table->string('email');
            // Hashed, expiring and single-use — see Kayra\Auth\PasswordBroker.
            $table->string('token', 64);
            $table->timestamp('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        // Reverse order: api_tokens references users.
        $this->schema()->dropIfExists('password_resets');
        $this->schema()->dropIfExists('api_tokens');
        $this->schema()->dropIfExists('users');
    }
};
