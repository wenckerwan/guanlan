<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateAdminAuditLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);           // 如 mistake.profile.replace
            $table->string('target_type', 32);      // 如 mistake_student
            $table->string('target_id', 64);        // 考生 code 或数字 id
            $table->text('detail')->nullable();     // JSON 细节
            $table->dateTime('created_at')->nullable();

            $table->index(['admin_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
}
