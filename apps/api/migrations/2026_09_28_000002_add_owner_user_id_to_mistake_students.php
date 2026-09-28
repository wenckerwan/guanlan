<?php

declare(strict_types=1);

use App\Support\AccountId;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class AddOwnerUserIdToMistakeStudents extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_students', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable()->unique()->after('id');
            $table->foreign('owner_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $accounts = Db::table('mistake_accounts')->orderBy('id')->get();
        foreach ($accounts as $account) {
            $user = Db::table('users')->where('id', (int) $account->user_id)->first();
            if (! $user) {
                continue;
            }
            $code = AccountId::format((int) $account->id);
            $existing = Db::table('mistake_students')->where('code', $code)->first();
            if ($existing) {
                Db::table('mistake_students')->where('id', (int) $existing->id)->update([
                    'owner_user_id' => (int) $user->id,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                continue;
            }
            Db::table('mistake_students')->insert([
                'owner_user_id' => (int) $user->id,
                'code' => $code,
                'name' => ((string) ($user->display_name ?: $code)) . '的错题分析',
                'relation' => '本人',
                'detail_html' => '',
                'sort_order' => 100000 + (int) $account->id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('mistake_students', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropUnique(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });
    }
}
