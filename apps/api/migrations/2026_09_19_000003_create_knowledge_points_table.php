<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateKnowledgePointsTable extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();
            $table->string('title', 191);
            $table->string('summary', 512)->default('');
            $table->string('category', 64)->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('source', 191)->nullable();
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index('category');
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_points');
    }
}
