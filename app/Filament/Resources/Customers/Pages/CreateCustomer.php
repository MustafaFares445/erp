<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerProfile;
use App\Services\Crm\CustomerDocumentSynchronizer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /** @var array<string> */
    private const array DocumentCollections = ['license', 'tax_certificate', 'passport', 'personal_identity', 'accommodation'];

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $documents = [];

        foreach (self::DocumentCollections as $collection) {
            $path = $data[$collection] ?? null;
            $documents[$collection] = is_string($path) ? $path : null;
            unset($data[$collection]);
        }

        $record = CustomerProfile::query()->create($data);

        $synchronizer = app(CustomerDocumentSynchronizer::class);

        foreach ($documents as $collection => $path) {
            $synchronizer->sync($record, $collection, $path);
        }

        return $record;
    }
}
