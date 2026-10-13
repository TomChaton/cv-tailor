<?php

namespace App\Validation\Checks;

use App\MasterCv\Markdown;
use App\MasterCv\RuleBlock;
use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;

/**
 * Nothing the master CV's "Do not claim" section names appears in the output.
 *
 * The section exists because a job description will ask for things the owner
 * has not done, and a model asked to tailor a CV to that description is under
 * every pressure to supply them. It is the author's own list of the claims
 * they will not make, so it outranks the job description and the owner's
 * instructions alike.
 *
 * **The terms are the item's bold lead-in**, which is the section's
 * convention: `**Kafka** — not recorded (RabbitMQ and AWS SQS are)`. Reading
 * the rest of the line would take "RabbitMQ" for a forbidden term and block a
 * CV for claiming something the CV supports. A parenthetical sitting directly
 * after the lead-in is part of it — `**Observability tooling beyond AWS
 * CloudWatch** (Datadog, Grafana, …)` — because there the brackets carry the
 * list; a parenthetical later in the line is explanation.
 *
 * **What is searched is what can reach the page.** `jd_analysis` and
 * `review_notes` are read by the owner in the review UI and never rendered:
 * they are where the output names the requirements the master CV does not
 * support, which is what the CV's own rules ask it to do. Searching them would
 * block a CV for correctly reporting a gap, on nearly every job that asks for
 * anything on the list, and a check that fires on correct behaviour is a check
 * that gets switched off. Everything else — the headline, the summary, the
 * skills and every selected item — is searched.
 */
class DoNotClaim implements Check
{
    /**
     * Fields the output uses to tell the owner something, as opposed to
     * fields that become the CV.
     */
    private const REVIEW_ONLY_FIELDS = ['jd_analysis', 'review_notes'];

    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'do_not_claim';
    }

    public function run(ValidationContext $context): array
    {
        $terms = self::termsIn($context->cv->doNotClaim);
        $findings = [];

        foreach ($this->claims($context->output, '') as $field => $text) {
            foreach ($terms as $term) {
                if (preg_match(self::pattern($term), $text) !== 1) {
                    continue;
                }

                $findings[] = Finding::blocking(
                    $this->name(),
                    (string) $field,
                    [],
                    $this->messages->get('do_not_claim.term', ['term' => $term]),
                );
            }
        }

        return $findings;
    }

    /**
     * The terms the section forbids.
     *
     * @return list<string>
     */
    public static function termsIn(RuleBlock $doNotClaim): array
    {
        $terms = [];

        foreach ($doNotClaim->items as $item) {
            foreach (self::split(self::leadIn($item)) as $term) {
                $terms[$term] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * The part of an item that names the terms, as opposed to explaining them.
     */
    private static function leadIn(string $item): string
    {
        if (preg_match('/^\*\*(?<terms>.+?)\*\*(?<after>.*)$/u', trim($item), $matches) === 1) {
            $terms = $matches['terms'];

            if (preg_match('/^\s*\((?<extra>[^()]*)\)/u', $matches['after'], $brackets) === 1) {
                $terms .= ', '.$brackets['extra'];
            }

            return $terms;
        }

        // No bold lead-in. The text before the dash is the closest thing to
        // one, and an item that names nothing matches nothing.
        foreach ([' — ', ' – ', ' - '] as $dash) {
            if (str_contains($item, $dash)) {
                return (string) strstr($item, $dash, before_needle: true);
            }
        }

        return $item;
    }

    /**
     * @return list<string>
     */
    private static function split(string $terms): array
    {
        $parts = [];

        foreach (Markdown::splitList($terms) as $part) {
            foreach (preg_split('/\s+(?:or|and)\s+/iu', $part) ?: [] as $term) {
                $term = trim($term, " \t.\u{2014}\u{2013}-");

                // A single character names nothing, and matching one would
                // block every CV.
                if (mb_strlen($term) > 1) {
                    $parts[] = $term;
                }
            }
        }

        return $parts;
    }

    /**
     * Every string in the output that could reach the page, by field.
     *
     * A recursive walk rather than a read of the typed selection: a field
     * added to the schema is searched the day it is added, where a walk over
     * named fields would quietly stop covering the output.
     *
     * @param  array<array-key, mixed>  $output
     * @return array<string, string>
     */
    private function claims(array $output, string $path): array
    {
        $claims = [];

        foreach ($output as $key => $value) {
            $field = $path === '' ? (string) $key : $path.(is_int($key) ? '['.$key.']' : '.'.$key);

            if (in_array($field, self::REVIEW_ONLY_FIELDS, true)) {
                continue;
            }

            if (is_string($value)) {
                $claims[$field] = $value;

                continue;
            }

            if (is_array($value)) {
                $claims = [...$claims, ...$this->claims($value, $field)];
            }
        }

        return $claims;
    }

    /**
     * @return non-empty-string
     */
    private static function pattern(string $term): string
    {
        return '/(?<![\p{L}\p{N}_])'.preg_quote($term, '/').'(?![\p{L}\p{N}_])/iu';
    }
}
