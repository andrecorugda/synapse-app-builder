<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Services\CredentialHosts;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 2.0: a Credential is sent only to the hosts listed on it (`allowed_hosts`,
 * exact or `*.example.com`); an empty list sends it nowhere.
 *
 * So that working integrations keep working, each existing credential starts
 * with the hosts its existing flows already call with it (HTTP Request nodes
 * that name the credential and a typed, token-free host). Review the result on
 * the Credentials screen or with `php artisan ai-page-builder:credential-hosts`;
 * see UPGRADE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = PbSchema::connection();
        $table = PbSchema::table('credentials');

        if (! Schema::connection($conn)->hasTable($table) || Schema::connection($conn)->hasColumn($table, 'allowed_hosts')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->json('allowed_hosts')->nullable()->after('meta');
        });

        try {
            $used = app(CredentialHosts::class)->hostsUsedByFlows();
        } catch (Throwable) {
            return; // no flows table yet (fresh install): nothing to carry over
        }

        foreach ($used as $key => $hosts) {
            $hosts = CredentialHosts::normalize($hosts);
            if ($hosts === []) {
                continue;
            }

            $updated = DB::connection($conn)->table($table)
                ->where('key', $key)
                ->whereNull('allowed_hosts')
                ->update(['allowed_hosts' => json_encode($hosts)]);

            if ($updated > 0) {
                Log::info('[ai-page-builder] credential "'.$key.'" may now be sent to the hosts its flows already call: '.implode(', ', $hosts).'. Review them on the Credentials screen.');
            }
        }
    }

    public function down(): void
    {
        $conn = PbSchema::connection();
        $table = PbSchema::table('credentials');

        if (! Schema::connection($conn)->hasColumn($table, 'allowed_hosts')) {
            return;
        }

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->dropColumn('allowed_hosts');
        });
    }
};
