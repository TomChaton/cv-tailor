<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->string('source_site');
            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->string('location')->nullable();
            $table->string('salary_text')->nullable();
            $table->text('raw_html');
            $table->text('extracted_text');
            $table->boolean('has_hidden_text');
            // A capture that matches an existing posting returns that posting,
            // so the hash is the thing that makes capture idempotent.
            $table->string('dedupe_hash')->unique();
            $table->string('status')->index();
            // No foreign key, deliberately: a posting points at a revision
            // which points back at the posting, and the constraint cannot be
            // added after the fact without SQLite-specific SQL. The
            // relationship is enforced in code.
            $table->unsignedBigInteger('current_revision_id')->nullable()->index();
            $table->unsignedBigInteger('applied_revision_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
