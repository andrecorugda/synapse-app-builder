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
            PbSchema::table('page_revisions'),
            function (Blueprint $table): void {
                $table->id();

                // Soft FK to the pages table (no constraint — the connection may
                // differ, and revisions outlive a soft-deleted page).
                $table->unsignedBigInteger('page_id')->index();

                $table->string('action', 20); // save|publish|restore|before_restore

                // Snapshot of the page's editable state at this point in time.
                $table->string('title', 200)->nullable();
                $table->string('status', 20)->nullable();
                $table->json('project_data')->nullable();        // canonical editor.getProjectData()
                $table->longText('html')->nullable();            // compiled render snapshot
                $table->longText('css')->nullable();
                $table->longText('custom_css')->nullable();
                $table->longText('custom_js')->nullable();
                $table->json('meta')->nullable();                // SEO snapshot

                // Soft FK to the host app's user table (no constraint).
                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())
            ->dropIfExists(PbSchema::table('page_revisions'));
    }
};
