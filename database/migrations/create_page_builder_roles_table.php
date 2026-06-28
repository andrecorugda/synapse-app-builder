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
            PbSchema::table('roles'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 120)->unique();
                $table->text('description')->nullable();
                // Admin roles bypass every permission check (full access).
                $table->boolean('is_admin')->default(false);
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('roles'));
    }
};
