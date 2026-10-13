<?php

namespace App\Generation;

use App\MasterCv\RoleMode;

/**
 * The JSON schema the model's answer is constrained to.
 *
 * Built per master CV version, because the ID enums are built from that
 * version's parse. A schema the provider has not seen before costs one slow
 * first request while it is compiled; the compilation is then cached for a
 * day, so editing the master CV carries a small one-off cost rather than a
 * standing one.
 *
 * `jd_analysis` comes first on purpose. Extracting the job's requirements
 * before selecting against them improves the selection, and the same field
 * drives the requirements checklist in the review UI.
 *
 * Every object sets `additionalProperties: false` and names every one of its
 * properties in `required`: structured outputs ask for both, and a field that
 * may be absent is a field the model can quietly decline to answer.
 *
 * **ID enums are scoped to the kind the field takes** — skills in
 * `key_skills`, employment roles in `roles`, projects in
 * `independent_projects`, bullets in a selected item. Only evidence takes the
 * broad list, because evidence legitimately spans every kind. This is what
 * makes a malformed selection impossible rather than detectable, and it is why
 * the two containers have shapes of their own instead of sharing one: an
 * independent project cannot be returned under an employer.
 *
 * Each repeated shape sits once in `$defs` and is referenced. A `$ref` carries
 * no sibling keywords — not every reader of JSON Schema takes them — so
 * anything that needs saying about a referenced field is said in the prompt.
 */
final readonly class OutputSchema
{
    private const ANY_ID = '#/$defs/master_cv_id';

    private const EMPLOYMENT_ROLE_ID = '#/$defs/employment_role_id';

    private const PROJECT_ID = '#/$defs/independent_project_id';

    private const ITEM_ID = '#/$defs/item_id';

    private const SKILL_ID = '#/$defs/skill_id';

    private const PROMOTABLE_ID = '#/$defs/role_or_project_id';

    private const HEADLINE_TITLE = '#/$defs/headline_title';

    private const ROLE_MODE = '#/$defs/role_mode';

    private const REQUIREMENT = '#/$defs/requirement';

    private const ROLE_SELECTION = '#/$defs/role_selection';

    private const PROJECT_SELECTION = '#/$defs/project_selection';

    private const SELECTED_ITEM = '#/$defs/selected_item';

    private const PROMOTION = '#/$defs/promotion';

    /**
     * @return array<string, mixed>
     */
    public function for(SelectableIds $ids): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'jd_analysis',
                'headline',
                'summary',
                'key_skills',
                'roles',
                'independent_projects',
                'review_notes',
            ],
            'properties' => [
                'jd_analysis' => [
                    'type' => 'array',
                    'description' => "The job's requirements, extracted before anything is selected.",
                    'items' => ['$ref' => self::REQUIREMENT],
                ],
                'headline' => ['$ref' => self::HEADLINE_TITLE],
                'summary' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['text', 'supports'],
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'supports' => self::listOf(
                            self::ANY_ID,
                            'The master CV IDs this text draws on.',
                        ),
                    ],
                ],
                'key_skills' => self::listOf(
                    self::SKILL_ID,
                    'Rows of the skills index. Never free text.',
                ),
                'roles' => [
                    'type' => 'array',
                    'description' => 'Every role in the employment history, including the ones left omitted.',
                    'items' => ['$ref' => self::ROLE_SELECTION],
                ],
                'independent_projects' => [
                    'type' => 'array',
                    'description' => 'Every independent project, including the ones left omitted.',
                    'items' => ['$ref' => self::PROJECT_SELECTION],
                ],
                'review_notes' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['gaps', 'judgement_calls', 'promotions'],
                    'properties' => [
                        'gaps' => self::stringList('Requirements the master CV does not support.'),
                        'judgement_calls' => self::stringList('Choices worth questioning.'),
                        'promotions' => [
                            'type' => 'array',
                            'description' => 'Every role shown in more detail than its default mode.',
                            'items' => ['$ref' => self::PROMOTION],
                        ],
                    ],
                ],
            ],
            '$defs' => $this->defs($ids),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defs(SelectableIds $ids): array
    {
        return [
            'requirement' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['requirement', 'priority', 'status', 'evidence', 'note'],
                'properties' => [
                    'requirement' => [
                        'type' => 'string',
                        'description' => 'The requirement as the job description states it.',
                    ],
                    'priority' => ['type' => 'string', 'enum' => ['must', 'nice']],
                    'status' => ['type' => 'string', 'enum' => ['met', 'partial', 'gap']],
                    'evidence' => self::listOf(
                        self::ANY_ID,
                        'The master CV IDs that evidence it. Empty for a gap.',
                    ),
                    'note' => ['type' => 'string', 'description' => 'One sentence for the owner.'],
                ],
            ],
            // Two shapes rather than one, so that the container decides the
            // kind of thing it may hold. A project returned under an employer
            // claims commercial experience nobody paid for, and here it cannot
            // be returned at all.
            'role_selection' => self::selection(self::EMPLOYMENT_ROLE_ID),
            'project_selection' => self::selection(self::PROJECT_ID),
            'selected_item' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'text', 'supports'],
                'properties' => [
                    'id' => ['$ref' => self::ITEM_ID],
                    'text' => [
                        'type' => ['string', 'null'],
                        'description' => "Null to use the master CV's own wording, which is the normal case.",
                    ],
                    'supports' => self::listOf(
                        self::ANY_ID,
                        'The master CV IDs a rewording draws on. Empty when text is null.',
                    ),
                ],
            ],
            'promotion' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'from', 'to', 'reason'],
                'properties' => [
                    'id' => ['$ref' => self::PROMOTABLE_ID],
                    'from' => ['$ref' => self::ROLE_MODE],
                    'to' => ['$ref' => self::ROLE_MODE],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Why this job warrants the space.',
                    ],
                ],
            ],
            'master_cv_id' => self::enum($ids->all),
            'employment_role_id' => self::enum($ids->employmentRoles),
            'independent_project_id' => self::enum($ids->independentProjects),
            'item_id' => self::enum($ids->items),
            'skill_id' => self::enum($ids->skills),
            'role_or_project_id' => self::enum($ids->promotable()),
            'headline_title' => self::enum($ids->headlineTitles),
            'role_mode' => self::enum(RoleMode::values()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function selection(string $idReference): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'mode', 'items'],
            'properties' => [
                'id' => ['$ref' => $idReference],
                'mode' => ['$ref' => self::ROLE_MODE],
                'items' => self::listOf(
                    self::SELECTED_ITEM,
                    'The selected items, in the order to show them.',
                ),
            ],
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listOf(string $reference, string $description): array
    {
        return [
            'type' => 'array',
            'description' => $description,
            'items' => ['$ref' => $reference],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringList(string $description): array
    {
        return [
            'type' => 'array',
            'description' => $description,
            'items' => ['type' => 'string'],
        ];
    }
}
