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
 * Each repeated shape sits once in `$defs` and is referenced. The ID enum is
 * the reason: a few hundred IDs repeated at every field that takes one would
 * be most of the request. A `$ref` carries no sibling keywords, so anything
 * that needs saying about a referenced field is said in the prompt.
 */
final readonly class OutputSchema
{
    private const ID = '#/$defs/master_cv_id';

    private const HEADLINE_TITLE = '#/$defs/headline_title';

    private const ROLE_MODE = '#/$defs/role_mode';

    private const REQUIREMENT = '#/$defs/requirement';

    private const ROLE_SELECTION = '#/$defs/role_selection';

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
                        'supports' => self::idList('The master CV IDs this text draws on.'),
                    ],
                ],
                'key_skills' => self::idList('Skill IDs from the skills index. Never free text.'),
                'roles' => [
                    'type' => 'array',
                    'description' => 'Every role in the employment history, including the ones left omitted.',
                    'items' => ['$ref' => self::ROLE_SELECTION],
                ],
                'independent_projects' => [
                    'type' => 'array',
                    'description' => 'Every independent project, including the ones left omitted.',
                    'items' => ['$ref' => self::ROLE_SELECTION],
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
                    'evidence' => self::idList('The master CV IDs that evidence it. Empty for a gap.'),
                    'note' => ['type' => 'string', 'description' => 'One sentence for the owner.'],
                ],
            ],
            'role_selection' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'mode', 'items'],
                'properties' => [
                    'id' => ['$ref' => self::ID],
                    'mode' => ['$ref' => self::ROLE_MODE],
                    'items' => [
                        'type' => 'array',
                        'description' => 'The selected items, in the order to show them.',
                        'items' => ['$ref' => self::SELECTED_ITEM],
                    ],
                ],
            ],
            'selected_item' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'text', 'supports'],
                'properties' => [
                    'id' => ['$ref' => self::ID],
                    'text' => [
                        'type' => ['string', 'null'],
                        'description' => "Null to use the master CV's own wording, which is the normal case.",
                    ],
                    'supports' => self::idList('The master CV IDs a rewording draws on. Empty when text is null.'),
                ],
            ],
            'promotion' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'from', 'to', 'reason'],
                'properties' => [
                    'id' => ['$ref' => self::ID],
                    'from' => ['$ref' => self::ROLE_MODE],
                    'to' => ['$ref' => self::ROLE_MODE],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Why this job warrants the space.',
                    ],
                ],
            ],
            'master_cv_id' => [
                'type' => 'string',
                'enum' => $ids->ids,
            ],
            'headline_title' => [
                'type' => 'string',
                'enum' => $ids->headlineTitles,
            ],
            'role_mode' => [
                'type' => 'string',
                'enum' => RoleMode::values(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function idList(string $description): array
    {
        return [
            'type' => 'array',
            'description' => $description,
            'items' => ['$ref' => self::ID],
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
