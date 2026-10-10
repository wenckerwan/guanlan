<?php
declare(strict_types=1);
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateArticleRevisions extends Migration
{
    public function up(): void
    {
        Schema::create('article_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type',16);
            $table->unsignedBigInteger('article_id');
            $table->unsignedInteger('revision');
            $table->longText('snapshot');
            // No user FK: audit attribution survives account deletion.
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->dateTime('created_at');
            $table->unique(['type','article_id','revision']);
        });
    }
    public function down(): void { Schema::dropIfExists('article_revisions'); }
}
