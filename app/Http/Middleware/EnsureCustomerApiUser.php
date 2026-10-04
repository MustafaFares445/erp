<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CustomerApprovalStatus;
use App\Enums\UserType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerApiUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->user_type === UserType::Customer
            && $user->customerProfile !== null,
            403,
            'This API is available only to customer accounts.',
        );

        // A suspended / unapproved customer keeps no access even with a token issued earlier.
        abort_unless(
            $user->customerProfile->is_active
            && $user->customerProfile->approval_status === CustomerApprovalStatus::Approved,
            403,
            'This customer account is not active.',
        );

        return $next($request);
    }
}
