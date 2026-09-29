<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUserStudyStatsTable extends Migration
{
    public function up(): void
    {
        Schema::create('user_study_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('seconds')->default(0);
            $table->unique(['user_id', 'date'], 'user_study_stats_unique_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_study_stats');
    }
}
