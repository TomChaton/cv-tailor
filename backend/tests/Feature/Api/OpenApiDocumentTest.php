<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpenApiDocumentTest extends TestCase
{
    /**
     * The committed document is what the extension's client is generated from,
     * so a contract nothing checks is a contract that drifts: the backend would
     * change and the extension would carry on being built against the old
     * shape.
     */
    public function test_the_committed_document_is_what_the_code_generates(): void
    {
        $generated = tempnam(sys_get_temp_dir(), 'openapi').'.json';

        $this->assertSame(0, Artisan::call('cv:openapi', ['--path' => $generated]));

        $this->assertSame(
            file_get_contents(base_path('openapi.json')),
            file_get_contents($generated),
            'The OpenAPI document no longer matches the code. Regenerate it with `cv:openapi`, '
            .'and regenerate the extension\'s client from it in the same change.',
        );

        unlink($generated);
    }

    /**
     * The document names no server. Every instance is self-hosted at an address
     * of its own, the client is handed a base URL, and a document carrying
     * whoever exported it's `APP_URL` would be wrong everywhere else.
     */
    public function test_the_document_names_no_server(): void
    {
        $this->assertArrayNotHasKey('servers', $this->document());
    }

    public function test_the_capture_endpoint_answers_201_for_a_new_posting_and_200_for_a_match(): void
    {
        $responses = data_get($this->document(), 'paths./api/v1/postings.post.responses');

        $this->assertIsArray($responses);
        $this->assertSame([201, 200, 401, 422, 413], array_keys($responses));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $document = json_decode(
            (string) file_get_contents(base_path('openapi.json')),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }
}
