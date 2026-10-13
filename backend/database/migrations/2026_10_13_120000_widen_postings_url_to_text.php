<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            // A job board's URL carries session keys and tracking parameters,
            // and passes 255 characters routinely. SQLite enforces no varchar
            // width, so a narrower column is a truncation waiting for the
            // first database that does — and moving to one of those is meant
            // to stay a configuration change.
            //
            // A migration of its own rather than an edit to the one that
            // created the table: a column nobody re-runs is a column whose
            // width depends on when the database was made.
            $table->text('url')->change();
        });
    }

    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->string('url')->change();
        });
    }
};
