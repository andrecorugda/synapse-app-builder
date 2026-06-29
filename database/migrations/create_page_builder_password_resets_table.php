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
            PbSchema::table('password_resets'),
            function (Blueprint $table): void {
                // Self-contained reset tokens for the pb guard — independent of
                // the host app's password_reset_tokens / broker config. The token
                // is stored HASHED (never plaintext); the emailed link carries
                // the plaintext, which is hashed and compared on reset.
                $table->string('email', 200)->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('password_resets'));
    }
};
