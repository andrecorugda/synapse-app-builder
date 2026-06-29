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
            PbSchema::table('users'),
            function (Blueprint $table): void {
                // Account lifecycle for self-registration + admin approval:
                //   active    — may log in
                //   pending   — registered, awaiting admin approval
                //   suspended — blocked by an admin
                // Existing rows default to active so current users keep working.
                $table->string('status', 20)->default('active')->index()->after('is_active');
                // Set when the user proves control of their email (reset / verify).
                $table->timestamp('email_verified_at')->nullable()->after('status');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('users'),
            function (Blueprint $table): void {
                $table->dropColumn(['status', 'email_verified_at']);
            }
        );
    }
};
