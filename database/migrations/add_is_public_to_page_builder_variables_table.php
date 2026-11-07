<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.0: a State is server-only unless it is marked client-visible. Only
 * `is_public` States are sent to rendered pages (window.__pbState / $store.app)
 * and only they may be overridden by a page's live value when it triggers a
 * flow. Existing States default to server-only; see UPGRADE.md for marking the
 * ones your pages bind to (`php artisan ai-page-builder:states --public=…`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = PbSchema::connection();
        $table = PbSchema::table('variables');

        if (Schema::connection($conn)->hasColumn($table, 'is_public')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->boolean('is_public')->default(false)->after('type');
        });

        // Cached renders from 1.x embed EVERY State: drop them so no page keeps
        // serving a now server-only State until the cache TTL runs out.
        try {
            app(PageRenderer::class)->flushAll();
        } catch (Throwable) {
            // No pages table / cache yet (fresh install) — nothing to flush.
        }
    }

    public function down(): void
    {
        $conn = PbSchema::connection();
        $table = PbSchema::table('variables');

        if (! Schema::connection($conn)->hasColumn($table, 'is_public')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->dropColumn('is_public');
        });
    }
};
