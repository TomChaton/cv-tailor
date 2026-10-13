<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per submission, not per revision: a revision that expires in
        // one batch and goes out again in the next has two rows, and which
        // batch carried which attempt survives.
        Schema::create('llm_batch_revision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_batch_id')->constrained();
            $table->foreignId('revision_id')->constrained();
            // What the provider made of this request. It stands at `awaiting`
            // until a result has been applied, which is what lets a poller
            // that died halfway through a batch re-run without dispatching
            // anything twice or dropping anything.
            $table->string('outcome');
            $table->timestamps();

            // A batch carries a revision once. The result that arrives for it
            // is applied by claiming this row, so a second attempt at the same
            // row has nothing left to claim.
            $table->unique(['llm_batch_id', 'revision_id']);
            // A revision's submission history, read from the revision's side.
            $table->index('revision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_batch_revision');
    }
};
