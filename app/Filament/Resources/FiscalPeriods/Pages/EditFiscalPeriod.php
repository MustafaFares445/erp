<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditFiscalPeriod extends EditRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = FiscalPeriodResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = self::accountingActor();

        if (! $actor instanceof User || ! $record instanceof FiscalPeriod) {
            throw new Halt;
        }

        return self::runAccountingOperation(
            fn (): FiscalPeriod => app(FiscalPeriodService::class)->update(
                $actor,
                $record,
                self::stringFrom($data['name'] ?? null),
                CarbonImmutable::parse(self::stringFrom($data['starts_at'] ?? null)),
                CarbonImmutable::parse(self::stringFrom($data['ends_at'] ?? null)),
            ),
        );
    }
}
