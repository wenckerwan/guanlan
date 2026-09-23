<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreatePapersTable extends Migration
{
    public function up(): void
    {
        Schema::create('papers', function (Blueprint $table) {
            $table->id();
            $table->string('pid', 64)->unique();
            $table->smallInteger('year');
            $table->string('label', 32)->default('');
            $table->string('kind', 16)->default('');
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->unsignedSmallInteger('total_score')->default(0);
            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->json('sections')->nullable();
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index('year');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('papers');
    }
}
