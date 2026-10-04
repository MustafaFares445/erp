<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportAutomationAction: string
{
    case AssignTeam = 'assign_team';
    case AutoAssign = 'auto_assign';
    case SetPriority = 'set_priority';
    case PostInternalNote = 'post_internal_note';
    case AddFollower = 'add_follower';
    case CreateFollowUp = 'create_follow_up';

    public function label(): string
    {
        return match ($this) {
            self::AssignTeam => __('Assign team'),
            self::AutoAssign => __('Auto-assign employee'),
            self::SetPriority => __('Set priority'),
            self::PostInternalNote => __('Post internal note'),
            self::AddFollower => __('Add follower'),
            self::CreateFollowUp => __('Create follow-up task'),
        };
    }
}
