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
        $table = PbSchema::table('variables');

        if (Schema::connection($conn)->hasColumn($table, 'shape')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            // Nested typed schema for an Object state: [{name,type,fields?/ref?}, …].
            // JSON storage; the builder UI + binding path pickers read it.
            $table->json('shape')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        $conn = PbSchema::connection();
        $table = PbSchema::table('variables');

        if (! Schema::connection($conn)->hasColumn($table, 'shape')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->dropColumn('shape');
        });
    }
};
