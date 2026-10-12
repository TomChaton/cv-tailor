<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_cv_versions', function (Blueprint $table) {
            $table->id();
            // Unique: parsing an unchanged CV reuses the row rather than
            // adding one, so every revision generated from the same CV points
            // at the same version.
            $table->string('hash')->unique();
            $table->text('raw_content');
            $table->json('parsed_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_cv_versions');
    }
};
