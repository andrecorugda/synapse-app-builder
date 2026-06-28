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
            PbSchema::table('media'),
            function (Blueprint $table): void {
                $table->id();

                $table->string('disk', 60)->default('public');
                $table->string('directory', 255)->default('');
                $table->string('filename', 255);
                $table->string('name', 255);              // original / display name
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->string('alt', 255)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())
            ->dropIfExists(PbSchema::table('media'));
    }
};
