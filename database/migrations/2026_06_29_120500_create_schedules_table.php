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
            PbSchema::table('schedules'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('cron_expression', 120); // e.g. */5 * * * *
                $table->string('target_type', 30); // flow|function
                $table->string('target_key', 120); // slug of the flow/function
                $table->json('args')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_run_at')->nullable();
                $table->string('last_status', 30)->nullable(); // ok|failed
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('schedules'));
    }
};
