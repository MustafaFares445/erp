<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    #[\Override]
    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Supplier
            ? $record->name
            : 'Supplier';
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Supplier) {
            return null;
        }

        return sprintf(
            '%s · %s · Confirmation %s',
            $record->code,
            $record->is_active ? 'Active' : 'Inactive',
            $record->requires_confirmation ? 'required' : 'not required',
        );
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Edit supplier'),
        ];
    }
}
