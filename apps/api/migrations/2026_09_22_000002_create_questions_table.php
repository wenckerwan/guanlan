<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateQuestionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('pid', 64);
            $table->smallInteger('year');
            $table->string('label', 32)->default('');
            $table->unsignedSmallInteger('no');
            $table->string('type', 16)->default('');
            $table->string('type_cn', 32)->default('');
            $table->string('sec', 16)->default('');
            $table->decimal('score', 5, 1)->default(0);
            $table->string('module', 16)->default('');
            $table->string('module_name', 64)->default('');
            $table->string('super', 16)->default('');
            $table->string('super_name', 64)->default('');
            $table->string('module_conf', 8)->default('');
            $table->string('kaodian', 191)->default('');
            $table->string('answer', 16)->default('');
            $table->string('trap', 64)->default('');
            $table->unsignedTinyInteger('n_opt')->default(0);
            $table->json('options')->nullable();
            $table->text('stem')->nullable();
            $table->text('material')->nullable();
            $table->text('answer_text')->nullable();
            $table->longText('analysis')->nullable();
            $table->string('accuracy', 16)->default('');
            $table->string('n_tried', 16)->default('');
            $table->string('q_src', 191)->default('');
            $table->unsignedTinyInteger('in_stats')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['pid', 'no']);
            $table->index('year');
            $table->index('module');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
}
