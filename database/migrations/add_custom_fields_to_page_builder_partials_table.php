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
                // Raw CSS/JS escape hatches, same as pages — a partial can carry
                // styles the Style Manager can't express (@keyframes, complex
                // selectors) and behaviour, merged into every page that embeds it.
                $table->longText('custom_css')->nullable()->after('css');
                $table->longText('custom_js')->nullable()->after('custom_css');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('partials'),
            function (Blueprint $table): void {
                $table->dropColumn(['custom_css', 'custom_js']);
            }
        );
    }
};
