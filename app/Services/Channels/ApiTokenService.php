<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class ApiTokenService
{
    /** @return array{token:string,token_type:string,channel:string,user:array{id:int,name:string,email:string|null}} */
    public function issue(string $identifier, string $password, string $deviceName): array
    {
        $normalized = mb_trim($identifier);
        $user = User::query()
            ->where('email', $normalized)
            ->orWhere('username', $normalized)
            ->first();

        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            throw new DomainException('The supplied credentials are invalid.');
        }

        $channel = match ($user->user_type) {
            UserType::Customer => $this->activeCustomer($user),
            UserType::Employee => $this->activeEmployee($user),
            default => throw new DomainException('Dashboard administrators do not authenticate through the mobile API.'),
        };

        $token = $user->createToken($deviceName, [$channel])->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'channel' => $channel,
            'user' => [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'email' => is_string($user->email) ? $user->email : null,
            ],
        ];
    }

    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    private function activeCustomer(User $user): string
    {
        $profile = $user->customerProfile;

        if (! $profile instanceof CustomerProfile || ! $profile->is_active) {
            throw new DomainException('The customer profile is inactive.');
        }

        return 'customer';
    }

    private function activeEmployee(User $user): string
    {
        $profile = $user->employeeProfile;

        if (! $profile instanceof EmployeeProfile || ! $profile->is_active) {
            throw new DomainException('The employee profile is inactive.');
        }

        return 'employee';
    }
}
