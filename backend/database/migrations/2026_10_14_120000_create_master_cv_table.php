<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Singular, and deliberately: this instance has one owner with one
        // master CV, so the table holds one row. The plural name beside
        // `master_cv_versions` would read as a second collection of versions,
        // which is the one thing this is not.
        Schema::create('master_cv', function (Blueprint $table) {
            $table->id();
            $table->text('markdown');
            // `updated_at` is when it was last saved. A column of its own
            // would be the same fact under a second name.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_cv');
    }
};
