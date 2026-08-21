<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts\Pages;

use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Models\ChartAccount;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditChartOfAccount extends EditRecord
{
    use InteractsWithAccountingServices;

    protected static string $resource = ChartOfAccountResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->using(function (ChartAccount $record): bool {
                    $actor = self::accountingActor();

                    if (! $actor instanceof User) {
                        return false;
                    }

                    self::runAccountingOperation(
                        fn () => app(ChartOfAccountService::class)->delete($actor, $record),
                    );

                    return true;
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = self::accountingActor();

        if (! $actor instanceof User || ! $record instanceof ChartAccount) {
            throw new Halt;
        }

        // `parent_id` is always passed, even when null, because the service only
        // runs its cycle check when the key is present — and clearing a parent is a
        // change the check must still see.
        return self::runAccountingOperation(
            fn (): ChartAccount => app(ChartOfAccountService::class)->update($actor, $record, [
                'account_type_id' => self::integerFrom($data['account_type_id'] ?? null),
                'code' => self::stringFrom($data['code'] ?? null),
                'name' => self::stringFrom($data['name'] ?? null),
                'parent_id' => self::nullableIntegerFrom($data['parent_id'] ?? null),
                'is_postable' => self::booleanFrom($data['is_postable'] ?? null),
                'is_active' => self::booleanFrom($data['is_active'] ?? null),
            ]),
        );
    }
}
