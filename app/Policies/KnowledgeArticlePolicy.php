<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\KnowledgeArticle;
use App\Models\User;

final class KnowledgeArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(SupportPermission::KnowledgeView->value);
    }

    public function view(User $user, KnowledgeArticle $article): bool
    {
        return $user->can(SupportPermission::KnowledgeView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(SupportPermission::KnowledgeManage->value);
    }

    public function update(User $user, KnowledgeArticle $article): bool
    {
        return $user->can(SupportPermission::KnowledgeManage->value);
    }

    public function delete(User $user, KnowledgeArticle $article): bool
    {
        return $user->can(SupportPermission::KnowledgeManage->value);
    }

    public function publish(User $user, KnowledgeArticle $article): bool
    {
        return $user->can(SupportPermission::KnowledgePublish->value);
    }
}
