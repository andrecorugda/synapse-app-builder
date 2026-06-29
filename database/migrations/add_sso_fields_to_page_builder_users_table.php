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
                // SSO identity: which provider an account was linked through and
                // its stable id there. Both null for password-only accounts.
                $table->string('provider', 40)->nullable()->after('email');
                $table->string('provider_id')->nullable()->after('provider');
                $table->index(['provider', 'provider_id']);
                // SSO-only / password-disabled accounts have no local password.
                $table->string('password')->nullable()->change();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('users'),
            function (Blueprint $table): void {
                $table->dropIndex(['provider', 'provider_id']);
                $table->dropColumn(['provider', 'provider_id']);
            }
        );
    }
};
