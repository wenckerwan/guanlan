<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 账号 ID：注册时生成，同时作为该账号错题本的编号。
 */
class AddMistakeCodeToUsersTable extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mistake_code', 32)->nullable()->unique()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['mistake_code']);
            $table->dropColumn('mistake_code');
        });
    }
}
