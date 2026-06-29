<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(PbSchema::connection())->create(
            PbApiToken::tableName(),
            function (Blueprint $table): void {
                $table->id();
                // The end-user (pb guard) this token acts as. Soft reference —
                // no FK constraint so the users table can be relocated/cleared.
                // Null owner = full access (no AccessControl scoping); document
                // this in the tokens UI.
                $table->unsignedBigInteger('pb_user_id')->nullable()->index();
                $table->string('name', 160);
                // sha256 hash of the plaintext token; the plaintext is shown once
                // at creation and never stored.
                $table->string('token', 64)->unique();
                // Optional ability scopes (reserved for future per-token gating).
                $table->json('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbApiToken::tableName());
    }
};
