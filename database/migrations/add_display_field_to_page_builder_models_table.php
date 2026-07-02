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
                // The field key used as the collection's human label wherever a
                // single column must stand in for a row (relation display, picker
                // tile, filter options). Nullable — when unset PbModel::displayField()
                // infers one from field TYPE. NEVER a magic column name.
                $table->string('display_field', 120)->nullable()->after('icon');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('models'),
            function (Blueprint $table): void {
                $table->dropColumn('display_field');
            }
        );
    }
};
