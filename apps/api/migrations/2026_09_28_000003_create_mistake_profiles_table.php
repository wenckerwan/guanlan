<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class CreateMistakeProfilesTable extends Migration
{
    public const DEFAULT_MARKDOWN = '错题分析内容将在管理员上传后显示。';

    public function up(): void
    {
        Schema::create('mistake_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('mistake_students')->cascadeOnDelete();
            $table->longText('markdown');
            $table->longText('html');
            $table->string('source_file', 191)->default('');
            $table->boolean('is_default')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        $now = date('Y-m-d H:i:s');
        foreach (Db::table('mistake_students')->get() as $student) {
            $text = self::DEFAULT_MARKDOWN;
            Db::table('mistake_profiles')->insert([
                'student_id' => (int) $student->id,
                'markdown' => $text,
                'html' => '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                'source_file' => 'default.md',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_profiles');
    }
}
