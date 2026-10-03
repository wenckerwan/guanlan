<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 史纲（近现代史时间实验室）数据集版本表。
 * 每行一个已发布版本，payload 为完整 HistoryDataset JSON；读取最新一条 status='published'。
 */
class CreateHistoryDatasetsTable extends Migration
{
    public function up(): void
    {
        Schema::create('history_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64)->unique();
            $table->json('payload');
            $table->string('status', 16)->default('published');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['status', 'id'], 'history_datasets_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('history_datasets');
    }
}
