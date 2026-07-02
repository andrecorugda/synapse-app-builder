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
        $conn = PbSchema::connection();
        $table = PbSchema::table('flow_runs');

        if (Schema::connection($conn)->hasColumn($table, 'watcher_id')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            // Provenance: which Watcher (if any) caused this run — powers the
            // watcher's Runs tab. Nullable (most runs aren't watcher-caused);
            // plain index, no FK so a deleted watcher doesn't cascade its runs.
            $table->unsignedBigInteger('watcher_id')->nullable()->after('flow_slug_snapshot')->index();
        });
    }

    public function down(): void
    {
        $conn = PbSchema::connection();
        $table = PbSchema::table('flow_runs');

        if (! Schema::connection($conn)->hasColumn($table, 'watcher_id')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->dropColumn('watcher_id');
        });
    }
};
