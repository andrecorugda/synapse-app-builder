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
            PbSchema::table('models'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('key', 120)->unique();        // collection slug (immutable identity)
                $table->string('table_name', 160)->unique();  // resolved physical table name
                $table->string('name', 160);
                $table->string('label_singular', 160)->nullable();
                $table->string('label_plural', 160)->nullable();
                $table->text('description')->nullable();
                $table->string('icon', 80)->nullable();
                $table->boolean('has_timestamps')->default(true);
                $table->boolean('has_soft_deletes')->default(false);
                $table->json('options')->nullable();          // misc (sort field, default sort, permissions…)
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('models'));
    }
};
