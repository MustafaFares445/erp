<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class NotificationDeliveryVolumeReportService
{
    /**
     * @return Collection<int, object{channel:string,status:string,decision:?string,total:int}>
     */
    public function summarize(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return NotificationDelivery::query()
            ->selectRaw('channel, status, decision, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('channel', 'status', 'decision')
            ->orderBy('channel')
            ->orderBy('status')
            ->orderBy('decision')
            ->get();
    }
}
