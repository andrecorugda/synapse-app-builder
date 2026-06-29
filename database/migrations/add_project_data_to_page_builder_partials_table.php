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
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('partials'),
            function (Blueprint $table): void {
                // Canonical GrapesJS editor state, so partials are edited in the
                // same visual builder as pages (all blocks + bindings). The html
                // /css columns remain the render snapshot the renderer injects.
                $table->json('project_data')->nullable()->after('slug');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('partials'),
            function (Blueprint $table): void {
                $table->dropColumn('project_data');
            }
        );
    }
};
