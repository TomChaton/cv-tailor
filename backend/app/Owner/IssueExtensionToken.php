<?php

namespace App\Owner;

use App\Models\User;
use App\Observability\EventLogger;

/**
 * Issues a Sanctum personal access token for the extension to send captures
 * with. The plaintext is returned to the caller and never stored or logged:
 * only its hash reaches the database.
 */
class IssueExtensionToken
{
    public function __construct(private readonly EventLogger $log) {}

    public function handle(User $owner, string $name): string
    {
        $token = $owner->createToken($name);

        $this->log->log('token.issued', [
            'user_id' => $owner->getKey(),
            'token_id' => $token->accessToken->getKey(),
            'token_name' => $name,
        ]);

        return $token->plainTextToken;
    }
}
