<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts\Pages;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Models\ChartAccount;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateChartOfAccount extends CreateRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = ChartOfAccountResource::class;

    /**
     * Delegates to {@see ChartOfAccountService} so the cycle check and the
     * automatic demotion of a parent that just gained a child cannot be skipped
     * by using the dashboard (FR-006, FR-008).
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
            fn (): ChartAccount => app(ChartOfAccountService::class)->create($actor, [
                'account_type_id' => self::integerFrom($data['account_type_id'] ?? null),
                'code' => self::stringFrom($data['code'] ?? null),
                'name' => self::stringFrom($data['name'] ?? null),
                'parent_id' => self::nullableIntegerFrom($data['parent_id'] ?? null),
                'is_postable' => self::booleanFrom($data['is_postable'] ?? null, default: true),
                'is_active' => self::booleanFrom($data['is_active'] ?? null, default: true),
            ]),
        );
    }
}
