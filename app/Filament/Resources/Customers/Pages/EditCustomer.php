<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerProfile;
use App\Services\Crm\CustomerDocumentSynchronizer;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /** @var array<string> */
    private const array DocumentCollections = ['license', 'tax_certificate', 'passport', 'personal_identity', 'accommodation'];

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof CustomerProfile) {
            return parent::handleRecordUpdate($record, $data);
        }

        $documents = [];

        foreach (self::DocumentCollections as $collection) {
            $path = $data[$collection] ?? null;
            $documents[$collection] = is_string($path) ? $path : null;
            unset($data[$collection]);
        }

        $record->update($data);

        $synchronizer = app(CustomerDocumentSynchronizer::class);

        foreach ($documents as $collection => $path) {
            $synchronizer->sync($record, $collection, $path);
        }

        return $record;
    }
}
