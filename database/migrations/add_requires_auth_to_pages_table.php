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
            PbSchema::table('pages'),
            function (Blueprint $table): void {
                // When true, the page is only served to a logged-in end-user
                // (the gate middleware redirects guests to the login page).
                $table->boolean('requires_auth')->default(false)->after('kind');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('pages'),
            function (Blueprint $table): void {
                $table->dropColumn('requires_auth');
            }
        );
    }
};
