<?php
declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class AddQuestionEditorMetadata extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('revision')->default(1);
        });
        Db::table('questions')->update(['sort_order' => Db::raw('no')]);
    }

    public function down(): void
    {
        Schema::table('questions', fn (Blueprint $table) => $table->dropColumn(['sort_order', 'revision']));
    }
}
