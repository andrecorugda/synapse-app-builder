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
            PbSchema::table('permissions'),
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('role_id')->index();
                // What is being secured: a collection (data) or a page (view).
                $table->string('resource_type', 20);            // collection | page
                // The collection key / page slug, or '*' for all of that type.
                $table->string('resource_key', 160)->default('*');
                // create | read | update | delete (collections) · view (pages) · '*'
                $table->string('action', 20);
                // Optional row-level rule applied to collection reads/writes,
                // e.g. {"owner_id": "$CURRENT_USER"} — null = unrestricted.
                $table->json('rule')->nullable();
                $table->timestamps();

                $table->unique(['role_id', 'resource_type', 'resource_key', 'action'], 'pb_perm_unique');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('permissions'));
    }
};
