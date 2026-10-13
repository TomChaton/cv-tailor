<?php

namespace App\Generation;

/**
 * What a returned role mode got wrong.
 */
enum RoleModeFindingKind: string
{
    /**
     * Less of a role than the policy's default. The model may only ever show
     * more: a CV that hides a role the owner's policy says to show has made a
     * decision that was not the model's to make.
     */
    case Demotion = 'demotion';

    /**
     * More of a role than the default, with no reason given. The promotion is
     * allowed; leaving the owner to guess why is not.
     */
    case PromotionWithoutReason = 'promotion_without_reason';
}
