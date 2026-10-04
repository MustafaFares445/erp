<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Enums\CustomerApprovalStatus;
use App\Enums\UserType;
use App\Http\Requests\Api\Customer\CustomerLoginRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CustomerAuthController
{
    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = User::query()
            ->where('user_type', UserType::Customer->value)
            ->where(function (Builder $query) use ($login): void {
                $query->where('username', $login)->orWhere('email', $login);
            })
            ->with('customerProfile')
            ->first();

        // Always run one hash comparison so response time does not reveal whether the account exists.
        $passwordMatches = Hash::check(
            $request->string('password')->toString(),
            $user instanceof User && $user->password !== '' ? $user->password : self::dummyHash(),
        );

        if (! $user instanceof User || ! $passwordMatches) {
            return response()->json(['message' => 'Invalid customer credentials.'], 422);
        }

        if ($user->customerProfile === null) {
            return response()->json(['message' => 'This customer account has no customer profile.'], 403);
        }

        if (! $user->customerProfile->is_active || $user->customerProfile->approval_status !== CustomerApprovalStatus::Approved) {
            return response()->json(['message' => 'This customer account is not active.'], 403);
        }

        $deviceName = $request->string('device_name')->trim()->toString();
        $token = $user->createToken($deviceName !== '' ? $deviceName : 'customer-app', ['customer:*']);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'username' => $user->username,
                'locale' => $user->locale,
            ],
            'customer' => [
                'id' => $user->customerProfile->getKey(),
                'company_name' => $user->customerProfile->company_name,
                'approval_status' => $user->customerProfile->approval_status->value,
                'is_active' => (bool) $user->customerProfile->is_active,
            ],
        ]);
    }

    private static function dummyHash(): string
    {
        return Cache::rememberForever('support.customer-api.dummy-password-hash', static fn (): string => Hash::make(Str::random(40)));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
