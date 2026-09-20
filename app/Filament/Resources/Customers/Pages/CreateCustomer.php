<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\CustomerProvisioningSource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Services\Crm\CustomerAccountProvisioningService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /** @var array<string> */
    private const array DocumentCollections = ['license', 'tax_certificate', 'passport', 'personal_identity', 'accommodation'];

    /**
     * Creates the customer's User account and CustomerProfile together
     * through {@see CustomerAccountProvisioningService}, replacing the old
     * flow that only attached a profile to an already-existing customer user.
     *
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

        $account = [
            'name' => $data['account_name'],
            'username' => $data['username'],
            'email' => $data['login_email'],
            'password' => $data['password'],
        ];

        unset($data['account_name'], $data['username'], $data['login_email'], $data['password']);

        return app(CustomerAccountProvisioningService::class)->provision(
            account: $account,
            profile: $data,
            documents: $documents,
            source: CustomerProvisioningSource::Dashboard,
        );
    }
}
