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
            PbSchema::table('watchers'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                // What is watched: a collection model event, or a global state change.
                $table->string('source_type', 30); // collection|state
                $table->string('source_key', 120); // collection = pbModel key; state = variable key
                // For collection: created|updated|deleted (ONE per watcher, so each event
                // can target a different flow). For state: 'changed'.
                $table->string('event', 30)->nullable();
                // collection → { criteria: { field: { op: value } } }
                // state      → { path, op, value, from, to }
                $table->json('config')->nullable();
                $table->string('target_type', 30); // flow|function
                $table->string('target_key', 120); // slug of the flow/function
                // Optional map of event payload → flow input (null = pass the default input).
                $table->json('input_map')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_fired_at')->nullable();
                $table->string('last_status', 30)->nullable(); // ok|failed
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // The dispatch hot-path filters on (source_type, source_key, event, is_active).
                $table->index(['source_type', 'source_key', 'event'], 'pb_watchers_source_idx');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('watchers'));
    }
};
