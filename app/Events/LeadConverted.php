<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\CustomerProfile;
use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class LeadConverted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public CustomerProfile $customer,
    ) {}
}
