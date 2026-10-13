<?php

namespace App\Validation;

/**
 * One thing validation has to say about a revision.
 *
 * It records the check that raised it, what that costs the revision, the
 * master CV IDs and the output field involved, and a message written for the
 * owner. The owner has to be able to see what was wrong without reading code,
 * so the message names the thing rather than describing a rule.
 *
 * A finding quotes CV and output content, so it belongs in `validation_json`
 * and in the review UI and never in a log line.
 */
final readonly class Finding
{
    /**
     * @param  string  $check  The check's stable name. Counts are logged
     *                         against it, so renaming one renames an
     *                         observability signal.
     * @param  ?string  $field  The output field involved, as a path
     *                          (`roles[0].items[1].text`).
     * @param  list<string>  $ids  The master CV IDs involved.
     */
    public function __construct(
        public string $check,
        public Severity $severity,
        public ?string $field,
        public array $ids,
        public string $message,
    ) {}

    /**
     * @param  list<string>  $ids
     */
    public static function blocking(string $check, ?string $field, array $ids, string $message): self
    {
        return new self($check, Severity::Blocking, $field, $ids, $message);
    }

    /**
     * @param  list<string>  $ids
     */
    public static function advisory(string $check, ?string $field, array $ids, string $message): self
    {
        return new self($check, Severity::Advisory, $field, $ids, $message);
    }

    public function blocks(): bool
    {
        return $this->severity === Severity::Blocking;
    }

    /**
     * @return array{check: string, severity: string, field: ?string, ids: list<string>, message: string}
     */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'severity' => $this->severity->value,
            'field' => $this->field,
            'ids' => $this->ids,
            'message' => $this->message,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $ids = [];

        foreach (is_array($stored['ids'] ?? null) ? $stored['ids'] : [] as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        $field = $stored['field'] ?? null;
        $severity = $stored['severity'] ?? null;

        return new self(
            check: is_string($stored['check'] ?? null) ? $stored['check'] : 'unknown',
            // An unreadable severity is read as blocking. A finding nobody can
            // classify must not be the reason a CV is let through.
            severity: is_string($severity) ? Severity::tryFrom($severity) ?? Severity::Blocking : Severity::Blocking,
            field: is_string($field) ? $field : null,
            ids: $ids,
            message: is_string($stored['message'] ?? null) ? $stored['message'] : '',
        );
    }
}
