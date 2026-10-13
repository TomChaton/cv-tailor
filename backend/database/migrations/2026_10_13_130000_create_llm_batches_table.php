<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `llm_batches` rather than `batches`: Laravel's database queue owns
        // `job_batches`, and the two names read as each other in a log line.
        Schema::create('llm_batches', function (Blueprint $table) {
            $table->id();
            // The provider's own identifier, and the only way back to the
            // results. Unique, because a second row for one provider batch
            // would be a second claim on the same results.
            $table->string('provider_batch_id')->unique();
            $table->string('state');
            $table->timestamp('submitted_at');
            // Null until the provider reports the batch has finished.
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('request_count');
            $table->timestamps();

            // The poller asks for every batch that still owes us results, and
            // that question is asked of the whole table every five minutes.
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_batches');
    }
};
