<?php

use App\Observability\Status\AccessSection;
use App\Observability\Status\BatchesSection;
use App\Observability\Status\ExtractionSection;
use App\Observability\Status\LlmSection;
use App\Observability\Status\MasterCvSection;
use App\Observability\Status\PostingsSection;
use App\Observability\Status\ProcessesSection;
use App\Observability\Status\QueueSection;
use App\Observability\Status\RevisionsSection;

return [

    // The application listens on plain HTTP and knows nothing about how traffic
    // reaches it, so the trusted set is deployment configuration. Empty trusts
    // no proxy; `*` trusts any. Authentication never relies on a proxy: see the
    // system briefing.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'api' => [
        // A capture carries a page's whole visible text, so the API states a
        // ceiling rather than letting the largest page anybody browses decide
        // how much memory this application uses. A body past it is 413.
        // Keep it below PHP's `post_max_size`, which is the only limit left
        // once a body arrives with no declared length.
        'max_body_bytes' => (int) env('CV_MAX_BODY_BYTES', 4 * 1024 * 1024),
    ],

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
    ],

    'llm' => [
        // The only outbound network traffic this application makes.
        'api_key' => env('ANTHROPIC_API_KEY', ''),

        // One model per purpose. Names are configuration because a model is a
        // deployment choice, not a fact about the code.
        'models' => [
            'generation' => env('LLM_GENERATION_MODEL', 'claude-opus-5'),
            'validation' => env('LLM_VALIDATION_MODEL', 'claude-haiku-4-5'),
            // Reading a page and reporting the job on it is an easy task and
            // does not want the generation model.
            'extraction' => env('LLM_EXTRACTION_MODEL', 'claude-haiku-4-5'),
        ],

        // Micros (millionths of a currency unit) per million tokens, per
        // model. Provider rates change without notice, so they are
        // configuration: a rate compiled into the code misreports silently,
        // and nothing in the system would say so.
        //
        // `cache_write` is the one-hour-lifetime rate, because the one-hour
        // lifetime is the only one this application asks for.
        'rates' => [
            'claude-opus-5' => [
                'input' => 5_000_000,
                'output' => 25_000_000,
                'cache_write' => 10_000_000,
                'cache_read' => 500_000,
            ],
            'claude-haiku-4-5' => [
                'input' => 1_000_000,
                'output' => 5_000_000,
                'cache_write' => 2_000_000,
                'cache_read' => 100_000,
            ],
        ],

        // The ceiling on one response, per purpose. A generation response is a
        // whole selection — every requirement the job states, every item chosen
        // from every role — and an extraction response carries a whole job
        // description back, so neither is a handful of tokens. A response that
        // reaches its ceiling comes back cut off mid-document and is reported
        // as unusable rather than stored, so raising this is the answer to
        // seeing that.
        'max_output_tokens' => [
            'generation' => (int) env('LLM_GENERATION_MAX_OUTPUT_TOKENS', 16000),
            'extraction' => (int) env('LLM_EXTRACTION_MAX_OUTPUT_TOKENS', 8000),
        ],

        // The batch API bills a percentage of the live rate. A percentage of
        // an integer rather than a multiplier, because money is never a float.
        'batch_percent' => 50,

        // Recorded responses the fake client answers from. Fixtures are
        // recorded against the fictional master CV, never a real one. Only the
        // fake reads this, and only the test environment binds the fake.
        'fixture_path' => base_path('tests/Fixtures/llm'),
    ],

    // The owner account and the extension's token, so a stack that is thrown
    // away comes back up ready to sign in to and to capture with. They sit
    // beside the LLM API key in the same file, so there is nowhere less safe
    // for them to be.
    //
    // An empty value means the step it belongs to does not run; it never means
    // a default is used. Seeding is refused where `app.env` is production: a
    // password in a file is not how a real instance should get its owner, and
    // there `cv:owner` and `cv:token` are run by hand.
    'credentials' => [
        'owner_name' => env('CV_OWNER_NAME', ''),
        'owner_email' => env('CV_OWNER_EMAIL', ''),
        'owner_password' => env('CV_OWNER_PASSWORD', ''),

        // The plaintext the extension sends. Only its hash is stored, exactly
        // as for a token the command generated.
        'extension_token' => env('CV_EXTENSION_TOKEN', ''),
    ],

    'batches' => [
        // How long a batch may stay open before the poller warns about it.
        // The provider's results expire, and a batch that is never collected
        // is work that was paid for and thrown away, so this wants to be well
        // inside the provider's own expiry rather than next to it.
        'stale_after_minutes' => (int) env('CV_BATCH_STALE_AFTER_MINUTES', 360),

        // How many attempts a revision gets before it is failed. An attempt is
        // spent by the provider failing to serve a request, never by it
        // refusing one: a request we built wrongly is not the revision's
        // fault, so it costs no attempt.
        'attempt_limit' => (int) env('CV_BATCH_ATTEMPT_LIMIT', 3),
    ],

    // `cv:status` renders these in order. A new area appends its own section
    // and leaves the ones above it alone.
    'status_sections' => [
        ProcessesSection::class,
        QueueSection::class,
        MasterCvSection::class,
        AccessSection::class,
        LlmSection::class,
        PostingsSection::class,
        ExtractionSection::class,
        RevisionsSection::class,
        BatchesSection::class,
    ],

];
