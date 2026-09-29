<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMistakeAnalysisReportsTable extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_analysis_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('student_code', 16);
            $table->string('title', 191)->default('');
            $table->longText('markdown');
            $table->dateTime('created_at')->nullable();

            $table->index(['user_id', 'student_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_analysis_reports');
    }
}
