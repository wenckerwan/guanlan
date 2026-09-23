<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMistakeHandbooksTable extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_handbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('mistake_students')->cascadeOnDelete();
            $table->string('module', 32)->default('');
            $table->string('title', 191);
            $table->longText('html')->nullable();
            $table->json('sections')->nullable();
            $table->string('source_file', 191)->default('');
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['student_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_handbooks');
    }
}
