<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMocksTable extends Migration
{
    public function up(): void
    {
        Schema::create('mocks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('title', 191);
            $table->string('summary', 512)->default('');
            $table->unsignedSmallInteger('total_score')->default(100);
            $table->unsignedSmallInteger('duration_minutes')->default(180);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->string('source_file', 191)->default('');
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mocks');
    }
}
