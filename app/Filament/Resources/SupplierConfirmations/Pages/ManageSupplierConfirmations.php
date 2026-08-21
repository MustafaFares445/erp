<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Pages;

use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class ManageSupplierConfirmations extends ManageRecords
{
    use InteractsWithPurchasingServices;

    protected static string $resource = SupplierConfirmationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data): SupplierConfirmation {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    throw new Halt;
                }

                $type = self::stringFrom($data['confirmable_type'] ?? null);

                // Resolved to a model rather than passed as a type string,
                // because the service restricts the morph by class and a string
                // would let an unsupported type through unchecked (V-09).
                $target = $this->resolveTarget($type, self::integerFrom($data['confirmable_id'] ?? null));

                return self::runPurchasingOperation(
                    fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->record(
                        $actor,
                        $target,
                        self::integerFrom($data['supplier_id'] ?? null),
                        self::nullableStringFrom($data['notes'] ?? null),
                    ),
                    'admin.purchasing.notifications.confirmation_recorded',
                );
            }),
        ];
    }

    private function resolveTarget(string $type, int $id): Model
    {
        if (! is_a($type, Model::class, true)) {
            throw new Halt;
        }

        /** @var Model $model */
        $model = $type::query()->findOrFail($id);

        return $model;
    }
}
