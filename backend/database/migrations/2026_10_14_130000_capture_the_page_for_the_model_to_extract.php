<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Capture carries the page's visible text and the page's own structured data,
 * and the backend decides what the posting says.
 *
 * `raw_html` and `extracted_text` were the extension's answer to which part of
 * a page is the job description. That answer cannot be revisited: the metadata
 * lives outside the container the extension picked, so what was not stored
 * cannot be re-read. The page's whole text can be, which is what `page_text`
 * holds.
 *
 * `description` is the job description a model found in that text, and
 * `extracted_at` says whether it has been asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            // Nullable only until the rows already there have a value: a NOT
            // NULL column cannot be added to a table that holds rows.
            $table->text('page_text')->nullable();
            // The page's `JobPosting` JSON-LD, as the page published it. Stored
            // rather than read and thrown away, because a retried extraction
            // reads it again and because the statements a page makes about
            // itself are raw material like the text is.
            $table->json('json_ld')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('extracted_at')->nullable();
        });

        DB::table('postings')->update(['page_text' => DB::raw('extracted_text')]);

        Schema::table('postings', function (Blueprint $table) {
            $table->text('page_text')->nullable(false)->change();
            $table->dropColumn(['raw_html', 'extracted_text']);
        });
    }

    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->text('raw_html')->nullable();
            $table->text('extracted_text')->nullable();
        });

        DB::table('postings')->update([
            'raw_html' => '',
            'extracted_text' => DB::raw('page_text'),
        ]);

        Schema::table('postings', function (Blueprint $table) {
            $table->text('raw_html')->nullable(false)->change();
            $table->text('extracted_text')->nullable(false)->change();
            $table->dropColumn(['page_text', 'json_ld', 'description', 'extracted_at']);
        });
    }
};
