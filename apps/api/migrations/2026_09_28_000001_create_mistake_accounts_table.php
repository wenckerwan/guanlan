<?php

declare(strict_types=1);

use App\Support\AccountId;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class CreateMistakeAccountsTable extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        $users = Db::table('users')
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $number = 1;
        foreach ($users as $user) {
            $timestamp = $user->created_at ?: date('Y-m-d H:i:s');
            Db::table('mistake_accounts')->insert([
                'id' => $number,
                'user_id' => (int) $user->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            Db::table('users')->where('id', (int) $user->id)->update([
                'mistake_code' => AccountId::format($number),
            ]);
            $number++;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_accounts');
    }
}
