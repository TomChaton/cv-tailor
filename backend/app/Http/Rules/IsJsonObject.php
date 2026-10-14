<?php

namespace App\Http\Rules;

use Closure;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A JSON object, as against a list or a scalar.
 *
 * Laravel's `array` rule accepts both, and the contract has to distinguish
 * them: a page publishes a `JobPosting` *object*, and the OpenAPI document's
 * request body is generated from these rules — so a field documented as a list
 * of strings would be handed to the extension's generated client as one, and
 * the two sides would disagree while both type-checked.
 *
 * `docs()` is how a rule states its own schema. Scramble applies it after the
 * plain string rules beside it, so the nullability this field's `nullable` rule
 * carries is restated here rather than inherited.
 */
final class IsJsonObject implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || array_is_list($value)) {
            $fail('The :attribute field must be a JSON object.');
        }
    }

    public function docs(OpenApiType $type, TypeTransformer $transformer): OpenApiType
    {
        $object = new ObjectType;

        // Whatever keys the page published. The shape of a `JobPosting` is
        // schema.org's to state, and reading it is the backend's work.
        $object->additionalProperties(new MixedType);
        $object->nullable(true);
        $object->setDescription($type->description);

        return $object;
    }
}
