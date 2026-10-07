<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class CreateSiteVisitsTables extends Migration
{
    public function up(): void
    {
        Schema::create('site_visit_totals', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('visits')->default(0);
            $table->date('started_at')->nullable();
        });
        Db::table('site_visit_totals')->insert(['id' => 1, 'visits' => 0]);
        Schema::create('site_visit_browsers', function (Blueprint $table) {
            $table->char('browser_hash', 64)->primary();
            $table->unsignedBigInteger('last_counted_at');
        });
        Schema::create('site_visit_days', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedBigInteger('visits')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visit_days');
        Schema::dropIfExists('site_visit_browsers');
        Schema::dropIfExists('site_visit_totals');
    }
}
