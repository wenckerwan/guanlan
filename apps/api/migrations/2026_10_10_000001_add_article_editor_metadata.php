<?php
declare(strict_types=1);
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddArticleEditorMetadata extends Migration
{
    public function up(): void
    {
        foreach (['hotspots','analysis_articles'] as $name) Schema::table($name, function (Blueprint $table) {
            $table->longText('markdown')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('content_source',16)->default('dataset');
        });
    }
    public function down(): void
    {
        foreach (['hotspots','analysis_articles'] as $name) Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['markdown','revision','content_source']));
    }
}
