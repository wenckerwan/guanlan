<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateHotspotsTable extends Migration
{
    public function up(): void
    {
        Schema::create('hotspots', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191)->unique();
            $table->string('level', 2)->default('A');
            $table->string('summary', 512)->default('');
            $table->string('type', 64)->default('');
            $table->string('tag', 64)->default('');
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['level', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspots');
    }
}
