<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateDocumentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191);
            $table->string('meta', 191)->default('');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('cover', 32)->default('');
            $table->string('tone', 32)->default('jade');
            $table->string('category', 64)->default('');
            $table->smallInteger('year')->nullable();
            $table->string('source', 191)->nullable();
            $table->string('file_path', 512)->nullable()->unique();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index('category');
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
}
