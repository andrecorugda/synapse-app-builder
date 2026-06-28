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
        Schema::connection(PbSchema::connection())->create(
            PbSchema::table('settings'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('key', 120)->unique();
                // JSON-encoded value (any scalar/array); decoded by the Settings
                // service. Sensitive values (e.g. SMTP password) are encrypted
                // before they reach here — see Settings::setEncrypted().
                $table->longText('value')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('settings'));
    }
};
