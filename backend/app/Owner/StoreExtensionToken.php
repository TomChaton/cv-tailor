<?php

namespace App\Owner;

use App\Models\User;
use App\Observability\EventLogger;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Stores a token the owner chose, rather than one this application generated.
 *
 * What it buys is an extension that keeps working against a stack whose
 * database was just discarded: the plaintext it was configured with once still
 * matches the hash the new database holds. Only the hash is stored, exactly as
 * for a generated token, and the plaintext is never logged.
 */
class StoreExtensionToken
{
    /**
     * What the extension's token is called when nobody says otherwise. The
     * seeding step replaces the token of this name rather than adding one, so
     * it has to be the same name `cv:token` defaults to.
     */
    public const string DEFAULT_NAME = 'extension';

    public function __construct(
        private readonly EventLogger $log,
        private readonly ValidationFactory $validator,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $owner, string $name, string $plainTextToken): PersonalAccessToken
    {
        $this->validator->make(
            ['token' => $plainTextToken],
            ['token' => ['required', 'string', 'min:32', 'not_regex:/\|/']],
            // Sanctum reads a token containing a pipe as `id|secret` and finds
            // it by that id. A discarded database will not hand out the same
            // ids again, so a token carrying one is the one thing this must not
            // be given. Messages name the rule and never the value.
            ['token.not_regex' => 'The token must not contain a pipe character.'],
        )->validate();

        // Replaces rather than accumulates: the seeding step runs on every
        // boot, and a token per boot is a row per boot.
        $owner->tokens()->where('name', $name)->delete();

        $token = $owner->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
            'expires_at' => null,
        ]);

        $this->log->log('token.seeded', [
            'token_id' => $token->getKey(),
            'token_name' => $name,
        ]);

        return $token;
    }
}
