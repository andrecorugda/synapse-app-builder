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
            PbSchema::table('users'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('name', 200);
                $table->string('email', 200)->unique();
                $table->string('password');
                // The end-user's role (nullable = no role yet). Soft reference —
                // no FK constraint so the roles table can be relocated/cleared.
                $table->unsignedBigInteger('role_id')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->rememberToken();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('users'));
    }
};
