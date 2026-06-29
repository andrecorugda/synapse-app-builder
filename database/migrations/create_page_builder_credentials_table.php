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
            PbSchema::table('credentials'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('key', 120)->unique()->index();
                $table->string('type', 20)->default('bearer'); // bearer|api_key|basic
                $table->text('secret'); // stored ENCRYPTED
                $table->json('meta')->nullable(); // {header_name, username}
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(PbSchema::connection())->dropIfExists(PbSchema::table('credentials'));
    }
};
