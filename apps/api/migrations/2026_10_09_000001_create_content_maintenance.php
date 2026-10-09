<?php
declare(strict_types=1);
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class CreateContentMaintenance extends Migration
{
    public function up(): void
    {
        Schema::create('content_maintenance', function (Blueprint $table) {
            $table->string('table_name', 64)->primary();
            $table->boolean('maintained')->default(false);
            $table->dateTime('updated_at')->nullable();
        });
        foreach (['analysis_articles', 'hotspots', 'papers', 'predictions', 'questions'] as $name) {
            Db::table('content_maintenance')->insert(['table_name' => $name, 'maintained' => false]);
        }
    }
    public function down(): void { Schema::dropIfExists('content_maintenance'); }
}
