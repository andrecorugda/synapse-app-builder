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
            PbSchema::table('record_revisions'),
            function (Blueprint $table): void {
                $table->id();
                // The PbModel (collection) key the changed record belongs to.
                $table->string('collection', 120);
                // The record's primary key. String so it survives non-integer PKs.
                $table->string('record_id', 191);
                $table->string('operation', 20); // created|updated|deleted
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                // The acting pb-guard end-user id, when one is authenticated.
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['collection', 'record_id']);
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('record_revisions'));
    }
};
