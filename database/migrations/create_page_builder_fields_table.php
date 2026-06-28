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
            PbSchema::table('fields'),
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('model_id')->index();
                $table->string('key', 120);                 // column name on the generated table
                $table->string('label', 160);
                $table->string('type', 30)->default('string'); // FieldType value
                $table->json('options')->nullable();         // required/unique/default/choices/relation_model/length…
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->unique(['model_id', 'key']);
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('fields'));
    }
};
