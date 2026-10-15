<?php

namespace App\Review;

use App\MasterCv\RoleMode;
use App\Validation\MasterCvIndex;

/**
 * What the model wrote for the owner about one revision.
 *
 * The sentences are the model's and are not summarised, reworded or filtered.
 * All this class does is read them out of the output and name the role a
 * promotion concerns.
 */
final readonly class ReviewNotes
{
    /**
     * @param  list<string>  $gaps
     * @param  list<string>  $judgementCalls
     * @param  list<Promotion>  $promotions
     */
    private function __construct(
        public array $gaps,
        public array $judgementCalls,
        public array $promotions,
    ) {}

    /**
     * No notes, for a posting with no revision to have written any.
     */
    public static function none(): self
    {
        return new self([], [], []);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public static function from(array $output, MasterCvIndex $index): self
    {
        $notes = $output['review_notes'] ?? null;
        $notes = is_array($notes) ? $notes : [];

        return new self(
            gaps: self::sentences($notes['gaps'] ?? null),
            judgementCalls: self::sentences($notes['judgement_calls'] ?? null),
            promotions: self::promotions($notes['promotions'] ?? null, $index),
        );
    }

    public function isEmpty(): bool
    {
        return $this->gaps === [] && $this->judgementCalls === [] && $this->promotions === [];
    }

    /**
     * @return list<string>
     */
    private static function sentences(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        $sentences = [];

        foreach ($notes as $note) {
            if (is_string($note) && trim($note) !== '') {
                $sentences[] = $note;
            }
        }

        return $sentences;
    }

    /**
     * @return list<Promotion>
     */
    private static function promotions(mixed $promotions, MasterCvIndex $index): array
    {
        if (! is_array($promotions)) {
            return [];
        }

        $read = [];

        foreach ($promotions as $promotion) {
            if (! is_array($promotion)) {
                continue;
            }

            $id = $promotion['id'] ?? null;
            $reason = $promotion['reason'] ?? null;

            if (! is_string($id)) {
                continue;
            }

            $role = $index->role($id);

            $read[] = new Promotion(
                // The employer the version holds under that ID, falling back
                // to the ID: a promotion naming nothing would read as a note
                // about no role at all.
                role: $role === null ? $id : $role->employer,
                from: self::mode($promotion['from'] ?? null),
                to: self::mode($promotion['to'] ?? null),
                reason: is_string($reason) ? $reason : '',
            );
        }

        return $read;
    }

    private static function mode(mixed $mode): ?RoleMode
    {
        return is_string($mode) ? RoleMode::tryFrom($mode) : null;
    }
}
