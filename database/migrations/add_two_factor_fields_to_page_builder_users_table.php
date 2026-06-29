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
                // Two-factor auth. method: 'totp' (authenticator app) | 'email'
                // (one-time code). secret + recovery codes are stored ENCRYPTED.
                // confirmed_at null = enrolment started but not yet verified (so
                // it does NOT gate login until the user proves it once).
                $table->string('two_factor_method', 10)->nullable()->after('email_verified_at');
                $table->text('two_factor_secret')->nullable()->after('two_factor_method');
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->table(
            PbSchema::table('users'),
            function (Blueprint $table): void {
                $table->dropColumn([
                    'two_factor_method',
                    'two_factor_secret',
                    'two_factor_recovery_codes',
                    'two_factor_confirmed_at',
                ]);
            }
        );
    }
};
