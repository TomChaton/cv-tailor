<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A revision renders twice: the PDF it is sent as and the Word file it is
     * edited as. Each is recorded on its own column, because either can be
     * absent on its own — a Word file that could not be written must not cost
     * the revision the PDF it already has.
     */
    public function up(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->string('docx_path')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->dropColumn('docx_path');
        });
    }
};
