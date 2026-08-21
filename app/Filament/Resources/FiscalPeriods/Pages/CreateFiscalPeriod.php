<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateFiscalPeriod extends CreateRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = FiscalPeriodResource::class;

    /**
     * Delegates to {@see FiscalPeriodService} rather than letting Filament write
     * the row directly, so the overlap and ordering rules cannot be bypassed by
     * using the dashboard (FR-014, FR-015).
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = self::accountingActor();

        if (! $actor instanceof User) {
            throw new Halt;
        }

        return self::runAccountingOperation(
            fn (): FiscalPeriod => app(FiscalPeriodService::class)->create(
                $actor,
                self::stringFrom($data['name'] ?? null),
                CarbonImmutable::parse(self::stringFrom($data['starts_at'] ?? null)),
                CarbonImmutable::parse(self::stringFrom($data['ends_at'] ?? null)),
            ),
        );
    }
}
