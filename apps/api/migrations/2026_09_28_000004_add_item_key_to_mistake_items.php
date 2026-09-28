<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class AddItemKeyToMistakeItems extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_items', function (Blueprint $table) {
            $table->string('item_key', 128)->nullable()->unique()->after('student_id');
            $table->string('content_hash', 64)->default('')->after('item_key');
            $table->string('origin', 16)->default('dataset')->after('content_hash');
        });

        // 为现有数据生成 item_key
        $items = Db::table('mistake_items')
            ->join('mistake_students', 'mistake_items.student_id', '=', 'mistake_students.id')
            ->select('mistake_items.*', 'mistake_students.code as student_code')
            ->get();

        foreach ($items as $item) {
            $itemKey = $this->generateItemKey(
                (string) $item->student_code,
                (string) $item->module,
                (string) $item->chapter,
                (string) $item->source_no
            );

            $contentHash = $this->generateContentHash($item);

            Db::table('mistake_items')
                ->where('id', (int) $item->id)
                ->update([
                    'item_key' => $itemKey,
                    'content_hash' => $contentHash,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('mistake_items', function (Blueprint $table) {
            $table->dropUnique(['item_key']);
            $table->dropColumn(['item_key', 'content_hash', 'origin']);
        });
    }

    private function generateItemKey(string $studentCode, string $module, string $chapter, string $sourceNo): string
    {
        return sprintf(
            '%s|%s|%s|%s',
            $studentCode,
            $module,
            $chapter,
            $sourceNo
        );
    }

    private function generateContentHash(object $item): string
    {
        $content = json_encode([
            'stem' => $item->stem,
            'options' => $item->options,
            'correct_answer' => $item->correct_answer,
        ], JSON_UNESCAPED_UNICODE);

        return substr(hash('sha256', $content), 0, 16);
    }
}
