<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * hotspots 从「首页卡片」升级为「时政长文」：
 * 新增 slug / priority / period / html / outline / word_count / source_file / published_at。
 *
 * 注：subject_id 保持 NOT NULL + 外键（由 Seeder 按文件映射到学科），
 * 因此不使用 Schema::change()，避免引入 doctrine/dbal。
 */
class ExtendHotspotsTable extends Migration
{
    public function up(): void
    {
        Schema::table('hotspots', function (Blueprint $table) {
            $table->string('slug', 191)->nullable();
            $table->string('priority', 2)->default('A');
            $table->string('period', 64)->default('');
            $table->longText('html')->nullable();
            $table->json('outline')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('source_file', 191)->default('');
            $table->date('published_at')->nullable();
            $table->unique('slug');
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::table('hotspots', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['period']);
            $table->dropColumn([
                'slug', 'priority', 'period', 'html', 'outline',
                'word_count', 'source_file', 'published_at',
            ]);
        });
    }
}
