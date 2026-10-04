<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\KnowledgeArticleCategory;
use App\Models\User;

final class KnowledgeArticleCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(SupportPermission::KnowledgeView->value);
    }

    public function view(User $user, KnowledgeArticleCategory $category): bool
    {
        return $user->can(SupportPermission::KnowledgeView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(SupportPermission::KnowledgeManage->value);
    }

    public function update(User $user, KnowledgeArticleCategory $category): bool
    {
        return $user->can(SupportPermission::KnowledgeManage->value);
    }

    public function delete(User $user, KnowledgeArticleCategory $category): bool
    {
        return $user->can(SupportPermission::KnowledgeManage->value);
    }
}
