<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the owner asked for a cover letter for this posting.
 *
 * It is a fact about the posting and not about one of its revisions: the owner
 * asked for a letter for this job, and that stays true however many times the
 * CV is generated again.
 *
 * Defaulted rather than nullable, because there is no third answer. A posting
 * nobody asked a letter for and a posting captured before the question existed
 * are the same posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->boolean('cover_letter_requested')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->dropColumn('cover_letter_requested');
        });
    }
};
