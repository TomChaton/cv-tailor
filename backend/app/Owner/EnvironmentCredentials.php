<?php

namespace App\Owner;

/**
 * The credentials the environment offers for seeding, as it offered them.
 *
 * An empty value means the step it belongs to does not run. Nothing here
 * carries a default: a credential nobody chose is not one this application
 * invents.
 */
class EnvironmentCredentials
{
    private function __construct(
        public readonly string $ownerName,
        public readonly string $ownerEmail,
        public readonly string $ownerPassword,
        public readonly string $extensionToken,
    ) {}

    /**
     * Values are trimmed, so a line `.env` left a space on is the same
     * credential rather than a different one.
     */
    public static function from(
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
        string $extensionToken,
    ): self {
        return new self(
            trim($ownerName),
            trim($ownerEmail),
            trim($ownerPassword),
            trim($extensionToken),
        );
    }

    /**
     * Whether every value the owner account needs is there.
     *
     * All three or none: a partly-filled set is a mistake worth saying out
     * loud, not two thirds of an account.
     */
    public function hasOwner(): bool
    {
        return $this->missingOwnerVariables() === [];
    }

    public function hasAnyOwnerVariable(): bool
    {
        return count($this->missingOwnerVariables()) < 3;
    }

    /**
     * The environment variable names behind the owner values that are empty.
     *
     * @return list<string>
     */
    public function missingOwnerVariables(): array
    {
        $missing = [];

        foreach ([
            'CV_OWNER_NAME' => $this->ownerName,
            'CV_OWNER_EMAIL' => $this->ownerEmail,
            'CV_OWNER_PASSWORD' => $this->ownerPassword,
        ] as $variable => $value) {
            if ($value === '') {
                $missing[] = $variable;
            }
        }

        return $missing;
    }

    public function hasExtensionToken(): bool
    {
        return $this->extensionToken !== '';
    }
}
