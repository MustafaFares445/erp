<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\CustomerReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired for every status change after submission — startReview, approve,
 * reject, and conversion all raise this with the request's new status
 * already applied.
 */
final class CustomerReturnRequestUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public CustomerReturnRequest $request,
    ) {}
}
