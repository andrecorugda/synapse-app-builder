<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(PbSchema::connection())->create(
            PbSchema::table('pages'),
            function (Blueprint $table): void {
                $table->id();

                $table->string('title', 200);
                $table->string('slug', 200)->unique();          // route key
                $table->string('status', 20)->default('draft')->index();
                $table->string('template', 60)->nullable();

                $table->json('project_data')->nullable();        // canonical editor.getProjectData()
                $table->longText('html')->nullable();            // compiled render snapshot
                $table->longText('css')->nullable();

                $table->json('meta')->nullable();                // SEO: title/description/og_image/canonical/noindex

                $table->timestamp('published_at')->nullable();

                // Soft FK to the host app's user table (no constraint — the
                // connection may differ from the package's).
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())
            ->dropIfExists(PbSchema::table('pages'));
    }
};
