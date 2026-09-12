<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;

final readonly class ChannelActorResolver
{
    public function authenticated(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new DomainException('An authenticated API user is required.');
        }

        return $user;
    }

    public function customer(Request $request): User
    {
        $user = $this->authenticated($request);
        $profile = $user->customerProfile;

        if (! $user->isCustomer() || ! $profile instanceof CustomerProfile || ! $profile->is_active) {
            throw new DomainException('This token is not linked to an active customer channel.');
        }

        return $user;
    }

    public function employee(Request $request): User
    {
        $user = $this->authenticated($request);
        $profile = $user->employeeProfile;

        if (! $user->isEmployee() || ! $profile instanceof EmployeeProfile || ! $profile->is_active) {
            throw new DomainException('This token is not linked to an active employee channel.');
        }

        return $user;
    }
}
