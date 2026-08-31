<?php

declare(strict_types=1);

use Kayra\Database\Blueprint;
use Kayra\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('posts');
    }
};
