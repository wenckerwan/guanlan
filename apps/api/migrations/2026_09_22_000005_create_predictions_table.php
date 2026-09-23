<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreatePredictionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('title', 191);
            $table->string('layer', 32)->default('');
            $table->string('priority', 2)->default('A');
            $table->string('summary', 512)->default('');
            $table->longText('html')->nullable();
            $table->json('outline')->nullable();
            $table->string('source_file', 191)->default('');
            $table->unsignedInteger('word_count')->default(0);
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index('layer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
}
