<?php

declare(strict_types=1);

use Kayra\Database\Blueprint;
use Kayra\Database\Migration;

/**
 * Posts for the demo application.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title', 200);
            $table->text('body');
            $table->timestamps();

            // Ordering the index list by user_id first means "this user's posts,
            // newest first" is answered by the index alone.
            $table->index('user_id');

            // Deleting an account takes its posts with it, in the database
            // rather than in application code that a background job could skip.
            $table->foreign('user_id', 'users', 'id', 'CASCADE');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('posts');
    }
};
