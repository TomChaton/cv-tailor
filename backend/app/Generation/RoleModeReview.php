<?php

namespace App\Generation;

use App\MasterCv\ParsedMasterCv;
use App\MasterCv\RoleMode;

/**
 * Checks the role modes a selection came back with against the ones the
 * presentation policy set.
 *
 * Code owns each role's default mode, worked out from the policy's two cutoff
 * years. The model may move a role up from there and say why; it may not move
 * one down. The schema cannot express that — the mode enum is the same three
 * values for every role, and constraining it per role would mean a separate
 * shape per role — so the rule is checked on the way back.
 *
 * This is the rule and not the findings pipeline: it reports what it sees and
 * stores nothing.
 */
final readonly class RoleModeReview
{
    /**
     * @param  array<string, mixed>  $output  The model's selection, as stored.
     * @return list<RoleModeFinding>
     */
    public function findings(ParsedMasterCv $cv, array $output): array
    {
        $defaults = $this->defaults($cv);
        $explained = $this->explainedPromotions($output);

        $findings = [];

        foreach ($this->selections($output) as $selection) {
            $id = $selection['id'] ?? null;
            $mode = $selection['mode'] ?? null;

            if (! is_string($id) || ! is_string($mode)) {
                continue;
            }

            $returned = RoleMode::tryFrom($mode);
            $default = $defaults[$id] ?? null;

            // An ID that names no role resolves to nothing, which the
            // ID-resolution check reports. Saying it twice, in two
            // vocabularies, would not help the owner.
            if ($returned === null || $default === null) {
                continue;
            }

            if ($returned->isDemotionOf($default)) {
                $findings[] = new RoleModeFinding($id, $default, $returned, RoleModeFindingKind::Demotion);

                continue;
            }

            if ($returned->isPromotionOf($default) && ! isset($explained[$id])) {
                $findings[] = new RoleModeFinding(
                    $id,
                    $default,
                    $returned,
                    RoleModeFindingKind::PromotionWithoutReason,
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, RoleMode>
     */
    private function defaults(ParsedMasterCv $cv): array
    {
        $defaults = [];

        foreach ($cv->allRoles() as $role) {
            if ($role->id !== null) {
                $defaults[$role->id] = $role->defaultMode;
            }
        }

        return $defaults;
    }

    /**
     * Roles the model gave a reason for promoting. A blank reason is no
     * reason.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, true>
     */
    private function explainedPromotions(array $output): array
    {
        $notes = $output['review_notes'] ?? null;
        $promotions = is_array($notes) ? ($notes['promotions'] ?? null) : null;

        if (! is_array($promotions)) {
            return [];
        }

        $explained = [];

        foreach ($promotions as $promotion) {
            if (! is_array($promotion)) {
                continue;
            }

            $id = $promotion['id'] ?? null;
            $reason = $promotion['reason'] ?? null;

            if (is_string($id) && is_string($reason) && trim($reason) !== '') {
                $explained[$id] = true;
            }
        }

        return $explained;
    }

    /**
     * Employment and independent projects together: the rule is the same for
     * both, and both carry a default mode.
     *
     * @param  array<string, mixed>  $output
     * @return list<array<array-key, mixed>>
     */
    private function selections(array $output): array
    {
        $selections = [];

        foreach (['roles', 'independent_projects'] as $key) {
            $group = $output[$key] ?? null;

            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $selection) {
                if (is_array($selection)) {
                    $selections[] = $selection;
                }
            }
        }

        return $selections;
    }
}
