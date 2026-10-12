<?php

use App\Observability\Status\MasterCvSection;
use App\Observability\Status\PostingsSection;
use App\Observability\Status\ProcessesSection;
use App\Observability\Status\QueueSection;
use App\Observability\Status\RevisionsSection;

return [

    'master_cv' => [
        // A read-only bind mount. The application only ever reads it.
        'path' => env('MASTER_CV_PATH', '/var/lib/cv-tailor/master-cv.md'),
    ],

    // The application listens on plain HTTP and knows nothing about how traffic
    // reaches it, so the trusted set is deployment configuration. Empty trusts
    // no proxy; `*` trusts any. Authentication never relies on a proxy: see the
    // system briefing.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

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

        // The ceiling on one response. A generation response is a whole
        // selection — every requirement the job states, every item chosen from
        // every role — so it needs room. A response that reaches the ceiling
        // comes back cut off mid-document and is reported as unusable rather
        // than stored, so raising this is the answer to seeing that.
        'max_output_tokens' => [
            'generation' => (int) env('LLM_GENERATION_MAX_OUTPUT_TOKENS', 16000),
        ],

        // The batch API bills a percentage of the live rate. A percentage of
        // an integer rather than a multiplier, because money is never a float.
        'batch_percent' => 50,

        // Recorded responses the fake client answers from. Fixtures are
        // recorded against the fictional master CV, never a real one. Only the
        // fake reads this, and only the test environment binds the fake.
        'fixture_path' => base_path('tests/Fixtures/llm'),
    ],

    // `cv:status` renders these in order. A new area appends its own section
    // and leaves the ones above it alone.
    'status_sections' => [
        ProcessesSection::class,
        QueueSection::class,
        MasterCvSection::class,
        PostingsSection::class,
        RevisionsSection::class,
    ],

];
