<?php

namespace App\Http\Requests;

use App\Postings\PostingCapture;
use App\Postings\PostingEvents;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CapturePostingRequest extends FormRequest
{
    /**
     * The rules are also the API contract: the OpenAPI document's request body
     * is generated from them, so a field added here reaches the extension's
     * client when the document is regenerated.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** The page the posting was captured from. */
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            /** The hostname, or the key of the extractor that read the page. */
            'source_site' => ['required', 'string', 'max:255'],
            /** The visible text of the job description. */
            'extracted_text' => ['required', 'string'],
            /** The extracted container's HTML, so extraction can be re-run later. */
            'raw_html' => ['required', 'string'],
            /** Whether the container held text a reader cannot see. */
            'has_hidden_text' => ['required', 'boolean'],
            /** Taken from the page where it says so, and omitted rather than guessed. */
            'title' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'salary_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toCapture(): PostingCapture
    {
        return new PostingCapture(
            url: $this->string('url')->toString(),
            sourceSite: $this->string('source_site')->toString(),
            extractedText: $this->string('extracted_text')->toString(),
            rawHtml: $this->string('raw_html')->toString(),
            hasHiddenText: $this->boolean('has_hidden_text'),
            title: $this->nullableString('title'),
            company: $this->nullableString('company'),
            location: $this->nullableString('location'),
            salaryText: $this->nullableString('salary_text'),
        );
    }

    /**
     * Named here rather than at the call site so a rejected capture is visible
     * in the logs: without it, an extension whose extraction has broken fails
     * silently on the only side anybody is watching.
     */
    protected function failedValidation(Validator $validator): void
    {
        // The container is how framework glue reaches a service; a form
        // request is constructed by the router rather than by the caller, so
        // there is no constructor to inject into.
        $this->container->make(PostingEvents::class)
            ->rejected(array_keys($validator->errors()->messages()));

        parent::failedValidation($validator);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
