<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateEmailVerificationLocksTable extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_locks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            // Domain-separated SHA-256 keys avoid storing raw email/IP here.
            $table->char('lock_key', 64)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_locks');
    }
}
