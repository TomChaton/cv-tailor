<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_id')->constrained();
            // Nullable because the first revision of a posting has no parent:
            // revisions form a tree, not a list.
            $table->foreignId('parent_revision_id')->nullable()->constrained('revisions');
            $table->foreignId('master_cv_version_id')->constrained();
            $table->string('source');
            $table->text('instructions')->nullable();
            $table->string('model');
            $table->string('prompt_version');
            // Four named columns rather than one usage blob: a cost report is
            // then a sum, not a walk over JSON.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            // Millionths of a unit of currency. Money is never a float.
            $table->unsignedInteger('cost_micros')->nullable();
            $table->json('output_json')->nullable();
            $table->json('resolved_json')->nullable();
            $table->json('validation_json')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            // Both shapes are asked for, and the compound index cannot serve
            // the second: the review UI wants one posting's revisions in a
            // given state, while the batch submitter and `cv:status` ask about
            // a state across every posting.
            $table->index(['posting_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
