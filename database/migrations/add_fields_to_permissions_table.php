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
            PbSchema::table('permissions'),
            function (Blueprint $table): void {
                // Field-level restriction: a list of field keys this grant covers.
                // null / empty = all fields (column-level access unrestricted).
                $table->json('fields')->nullable()->after('rule');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('permissions'),
            function (Blueprint $table): void {
                $table->dropColumn('fields');
            }
        );
    }
};
