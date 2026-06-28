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
            PbSchema::table('functions'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 120)->unique();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('runtime', 20)->default('expression'); // expression|callable
                $table->longText('body')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('functions'));
    }
};
