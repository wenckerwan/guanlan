<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddStatusToContentTables extends Migration
{
    public function up(): void
    {
        // default 同时回填存量行；hidden 的内容对前台隐藏，后台仍可见可改
        Schema::table('hotspots', function (Blueprint $table) {
            $table->string('status', 16)->default(\App\Support\ContentStatus::PUBLISHED)->after('html');
        });

        Schema::table('analysis_articles', function (Blueprint $table) {
            $table->string('status', 16)->default(\App\Support\ContentStatus::PUBLISHED)->after('html');
        });
    }

    public function down(): void
    {
        Schema::table('hotspots', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('analysis_articles', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}
