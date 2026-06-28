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
            PbSchema::table('variables'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('key', 120)->unique();
                $table->string('type', 20)->default('string'); // string|number|boolean|json
                $table->longText('value')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_protected')->default(false);
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('variables'));
    }
};
