<?php

namespace App\Llm;

use RuntimeException;

/**
 * A model was asked for that configuration does not describe.
 *
 * It raises rather than falling back. A missing model name would otherwise
 * reach the provider as an empty string, and a missing cost rate would report
 * every revision as free.
 */
final class UnknownModel extends RuntimeException
{
    public static function forPurpose(LlmPurpose $purpose): self
    {
        return new self(sprintf(
            'No model is configured for %s. Set cv.llm.models.%s.',
            $purpose->value,
            $purpose->value,
        ));
    }

    public static function forRates(string $requestedModel): self
    {
        // The requested model, named as such: the provider answers about a
        // dated build of an alias, and a message that did not say which of the
        // two names it held would send the owner looking for a model they
        // never configured.
        return new self(sprintf(
            'No cost rates are configured for the requested model %s. Add it to cv.llm.rates.',
            $requestedModel,
        ));
    }
}
