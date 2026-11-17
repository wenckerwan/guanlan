<?php
declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateRegistrationQuotasTable extends Migration
{
    public function up(): void
    {
        Schema::create('registration_quotas', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->char('quota_key', 64)->primary();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('expires_at');
        });
    }

    public function down(): void { Schema::dropIfExists('registration_quotas'); }
}
