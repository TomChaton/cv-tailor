<?php

namespace App\Owner;

use App\Observability\EventLogger;
use Illuminate\Validation\ValidationException;

/**
 * Brings the owner account and the extension's token up from the environment.
 *
 * A stack that is thrown away — a branch under review, a database discarded
 * with `down -v` — otherwise pays for both by hand on every boot, and the
 * extension pays twice because a freshly generated token has to be pasted into
 * the browser before it will send anything. The credentials already sit beside
 * the LLM API key in the same file, so putting them there costs no secrecy and
 * removes one reason not to run a branch before merging it.
 *
 * Every outcome is a log line and none of them is printed: the password and the
 * token appear in neither, in part or in whole.
 */
class SeedCredentials
{
    public function __construct(
        private readonly EventLogger $log,
        private readonly OwnerAccount $owners,
        private readonly UpsertOwner $upsertOwner,
        private readonly StoreExtensionToken $storeToken,
        private readonly EnvironmentCredentials $credentials,
        private readonly string $appEnv,
    ) {}

    /**
     * @return bool Whether seeding was allowed to run at all. What it then did
     *              is in the log, which is the only place any of it is said.
     */
    public function handle(): bool
    {
        // A password sitting in a file is not how a real instance should get
        // its owner, so the convenience stops at the edge of one. `cv:owner`
        // and `cv:token` still run by hand there, which is how an instance
        // somebody else hosts is set up.
        if ($this->appEnv === 'production') {
            $this->log->log('credentials.seeding_refused', ['app_env' => $this->appEnv]);

            return false;
        }

        $this->seedOwner();
        $this->seedToken();

        return true;
    }

    private function seedOwner(): void
    {
        if (! $this->credentials->hasOwner()) {
            // Nothing set at all is the ordinary case and says nothing. Some
            // of it set is a mistake that would otherwise look like the
            // feature not working.
            if ($this->credentials->hasAnyOwnerVariable()) {
                $this->log->warning('credentials.seeding_incomplete', [
                    'missing' => $this->credentials->missingOwnerVariables(),
                ]);
            }

            return;
        }

        try {
            $owner = $this->upsertOwner->handle(
                $this->credentials->ownerName,
                $this->credentials->ownerEmail,
                $this->credentials->ownerPassword,
            );
        } catch (ValidationException $e) {
            $this->rejected($e);

            return;
        }

        // Not the email, which is a contact detail, and certainly not the
        // password.
        $this->log->log('owner.seeded', ['user_id' => $owner->getKey()]);
    }

    private function seedToken(): void
    {
        if (! $this->credentials->hasExtensionToken()) {
            return;
        }

        $owner = $this->owners->find();

        if ($owner === null) {
            $this->log->warning('credentials.seeding_incomplete', ['missing' => ['owner']]);

            return;
        }

        try {
            // `token.seeded` is written where the token is stored, so a token
            // the owner chose is reported whether the seeding step or
            // `cv:token` stored it.
            $this->storeToken->handle($owner, StoreExtensionToken::DEFAULT_NAME, $this->credentials->extensionToken);
        } catch (ValidationException $e) {
            $this->rejected($e);
        }
    }

    /**
     * Validation messages name the rule that refused a value, never the value
     * itself, so they are safe to log and are the only way to find out why a
     * seeded credential was turned down.
     */
    private function rejected(ValidationException $e): void
    {
        $this->log->error('credentials.seeding_invalid', [
            'messages' => array_values($e->validator->errors()->all()),
        ]);
    }
}
