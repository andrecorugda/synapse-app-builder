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
            PbSchema::table('user_invites'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('email', 200)->index();
                // Stored HASHED; the plaintext lives only in the emailed link.
                $table->string('token');
                // Role the invitee receives on accept (soft ref to roles, no FK).
                $table->unsignedBigInteger('role_id')->nullable();
                // Host admin (panel user) who sent it — informational, no FK.
                $table->unsignedBigInteger('invited_by')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('user_invites'));
    }
};
