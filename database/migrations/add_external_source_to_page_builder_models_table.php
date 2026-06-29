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
            PbSchema::table('models'),
            function (Blueprint $table): void {
                // External data sources: a collection can be 'managed' (the
                // package owns its table — the default) or 'external' (it maps to
                // an EXISTING table on another connection, which the package reads
                // but never creates/alters/drops). `is_read_only` blocks writes
                // through the API for either kind.
                $table->string('source_type', 20)->default('managed')->after('table_name');
                $table->string('source_connection', 120)->nullable()->after('source_type');
                $table->boolean('is_read_only')->default(false)->after('source_connection');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('models'),
            function (Blueprint $table): void {
                $table->dropColumn(['source_type', 'source_connection', 'is_read_only']);
            }
        );
    }
};
