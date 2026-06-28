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
            PbSchema::table('flow_runs'),
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('flow_id')->index();
                $table->string('flow_slug_snapshot', 120)->nullable();
                $table->string('status', 20)->default('ok'); // ok|error
                $table->string('trigger_type', 30)->nullable();
                $table->json('input')->nullable();
                $table->json('result')->nullable();   // { actions:[...], vars:{...} }
                $table->json('steps')->nullable();     // per-node trace
                $table->text('error')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('flow_runs'));
    }
};
