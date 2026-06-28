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
            PbSchema::table('flows'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 120)->unique();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('trigger_type', 30)->default('manual'); // manual|component|form|cron|api
                $table->json('trigger_config')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('is_public')->default(false); // may be triggered from a public page
                $table->unsignedInteger('rate_limit_per_minute')->nullable();
                $table->json('definition')->nullable(); // { start, nodes{ id => {type,config,next|next_true|next_false} } }
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('flows'));
    }
};
