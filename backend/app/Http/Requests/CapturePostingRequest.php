<?php

namespace App\Http\Requests;

use App\Http\Rules\IsJsonObject;
use App\Postings\AskedFor;
use App\Postings\PostingCapture;
use App\Postings\PostingEvents;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CapturePostingRequest extends FormRequest
{
    /**
     * The rules are also the API contract: the OpenAPI document's request body
     * is generated from them, so a field added here reaches the extension's
     * client when the document is regenerated.
     *
     * @return array<string, list<string|Enum|IsJsonObject>>
     */
    public function rules(): array
    {
        return [
            /** The page the posting was captured from. */
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            /** The hostname the page was read on. */
            'source_site' => ['required', 'string', 'max:255'],
            /** The page's visible text, whole and uninterpreted. */
            'page_text' => ['required', 'string'],
            /** The page's `JobPosting` JSON-LD object, where it published one. */
            'json_ld' => ['nullable', new IsJsonObject],
            /** Whether the page held text a reader cannot see. */
            'has_hidden_text' => ['required', 'boolean'],
            /** What the owner asked for. Absent asks to keep the posting. */
            'asked_for' => ['sometimes', Rule::enum(AskedFor::class)],
        ];
    }

    public function toCapture(): PostingCapture
    {
        return new PostingCapture(
            url: $this->string('url')->toString(),
            sourceSite: $this->string('source_site')->toString(),
            pageText: $this->string('page_text')->toString(),
            hasHiddenText: $this->boolean('has_hidden_text'),
            jsonLd: $this->jsonLd(),
            // A capture that said nothing asked to keep the posting, so a
            // caller that knows nothing about the field behaves as capture
            // always has.
            askedFor: $this->enum('asked_for', AskedFor::class) ?? AskedFor::Save,
        );
    }

    /**
     * Named here rather than at the call site so a rejected capture is visible
     * in the logs: without it, an extension whose capture has broken fails
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

    /**
     * @return array<string, mixed>|null
     */
    private function jsonLd(): ?array
    {
        $value = $this->input('json_ld');

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
