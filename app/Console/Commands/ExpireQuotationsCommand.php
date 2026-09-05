<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Services\Sales\QuotationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('sales:quotations:expire')]
#[Description('Transition lapsed sent quotations to Expired, releasing any reservation they hold.')]
final class ExpireQuotationsCommand extends Command
{
    public function handle(QuotationService $quotations): int
    {
        $expired = 0;
        $failed = 0;

        Quotation::query()
            ->where('status', QuotationStatus::Sent->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', today())
            ->orderBy('id')
            ->chunkById(500, function (Collection $batch) use ($quotations, &$expired, &$failed): void {
                foreach ($batch as $quotation) {
                    try {
                        $quotations->expire($quotation);
                        $expired++;
                    } catch (Throwable $exception) {
                        $failed++;

                        $this->components->error(sprintf(
                            'Quotation #%d failed to expire: %s',
                            (int) $quotation->getKey(),
                            $exception->getMessage(),
                        ));
                    }
                }
            });

        $this->components->info(sprintf(
            'Quotation expiry sweep completed: %d expired, %d failed.',
            $expired,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
