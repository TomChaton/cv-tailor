<?php

namespace App\Llm;

/**
 * What a request to the provider is for.
 *
 * The purpose chooses the model — one for generation, one for validation, one
 * for extraction — and names the request in the log, so a cost report can be
 * split by what the spend bought.
 */
enum LlmPurpose: string
{
    case Generation = 'generation';
    case Validation = 'validation';
    case Extraction = 'extraction';
}
