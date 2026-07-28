<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ProductStatus;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final readonly class CatalogImportValidator
{
    public function __construct(private InventoryIdentityGuard $inventoryIdentityGuard) {}

    /** @var list<string> */
    private const array BASE_COLUMNS = [
        'sku', 'product_name', 'product_name_ar', 'variant_name', 'variant_name_ar', 'product_status',
        'brand_code', 'brand_name', 'category_name', 'parent_category_name', 'unit_symbol', 'unit_name',
        'allows_decimal', 'barcode', 'track_serials', 'track_expiry', 'cost_price', 'base_price', 'min_price',
        'markup_percent', 'supplier_code', 'supplier_name', 'supplier_item_number', 'country_code', 'manufacturer',
        'currency_code', 'warehouse_code', 'quantity', 'serial_number', 'iot_number', 'lot_number', 'expires_at',
    ];

    /** @return list<string> */
    public function templateColumns(): array
    {
        return [
            ...self::BASE_COLUMNS,
            ...$this->activeAttributes()
                ->map(fn (ProductAttribute $attribute): string => 'attribute_'.$this->normalizeCode($attribute->code))
                ->values()
                ->all(),
        ];
    }

    /** @return Collection<string, ProductAttribute> */
    public function activeAttributes(): Collection
    {
        /** @var Collection<int, ProductAttribute> $attributes */
        $attributes = ProductAttribute::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return $attributes->keyBy(fn (ProductAttribute $attribute): string => $this->normalizeCode($attribute->code));
    }

    /** @param list<string> $header */
    public function assertRequiredColumns(array $header): void
    {
        if (
            array_diff(['sku', 'product_name', 'variant_name'], $header) !== []
            || count($header) !== count(array_unique($header))
        ) {
            throw new DomainException(__('admin.inventory.import.errors.invalid_template'));
        }
    }

    /**
     * @param  array<string, string>  $payload
     * @param  Collection<string, ProductAttribute>  $attributes
     * @return array<string, list<string>>
     */
    public function validate(array $payload, Collection $attributes): array
    {
        $errors = $this->validateRequiredAndScalarFields($payload);
        $errors = $this->validateInventoryFields($payload, $errors);

        return $this->validateAttributes($payload, $attributes, $errors);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, list<string>>
     */
    private function validateRequiredAndScalarFields(array $payload): array
    {
        $errors = [];

        foreach (['sku', 'product_name', 'variant_name'] as $column) {
            if (($payload[$column] ?? '') === '') {
                $errors = $this->addError($errors, $column, 'required');
            }
        }

        foreach (['cost_price', 'base_price', 'min_price', 'markup_percent'] as $column) {
            if (isset($payload[$column]) && ! is_numeric($payload[$column])) {
                $errors = $this->addError($errors, $column, 'numeric');
            }
        }

        if (isset($payload['product_status']) && ProductStatus::tryFrom($payload['product_status']) === null) {
            return $this->addError($errors, 'product_status', 'invalid');
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function validateInventoryFields(array $payload, array $errors): array
    {
        if (! $this->hasInventoryData($payload)) {
            return $errors;
        }

        if (($payload['warehouse_code'] ?? '') === '') {
            $errors = $this->addError($errors, 'warehouse_code', 'required_for_inventory');
        }

        if (($payload['quantity'] ?? '') === '') {
            $errors = $this->addError($errors, 'quantity', 'required_for_inventory');
        } elseif (! is_numeric($payload['quantity']) || (float) $payload['quantity'] <= 0) {
            $errors = $this->addError($errors, 'quantity', 'positive');
        }

        $errors = $this->validateWarehouse($payload, $errors);
        $errors = $this->validateSerializedFields($payload, $errors);

        return $this->validateExpiryFields($payload, $errors);
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function validateWarehouse(array $payload, array $errors): array
    {
        $code = $payload['warehouse_code'] ?? null;

        if ($code === null) {
            return $errors;
        }

        $exists = Warehouse::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->where('is_active', true)
            ->exists();

        return $exists ? $errors : $this->addError($errors, 'warehouse_code', 'unknown_or_inactive');
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function validateSerializedFields(array $payload, array $errors): array
    {
        if (! $this->tracksSerials($payload)) {
            return $errors;
        }

        if (($payload['serial_number'] ?? '') === '') {
            $errors = $this->addError($errors, 'serial_number', 'required_when_tracking_serials');
        }

        if (isset($payload['quantity']) && is_numeric($payload['quantity']) && (float) $payload['quantity'] !== 1.0) {
            $errors = $this->addError($errors, 'quantity', 'serialized_quantity_must_be_one');
        }

        $errors = $this->validateIdentity(
            $payload['serial_number'] ?? null,
            'serial_number',
            $errors,
            fn (string $value): bool => $this->identityIsAvailable(
                function () use ($value): void {
                    $this->inventoryIdentityGuard->ensureSerialAvailable($value);
                },
            ),
        );

        return $this->validateIdentity(
            $payload['iot_number'] ?? null,
            'iot_number',
            $errors,
            fn (string $value): bool => $this->identityIsAvailable(
                function () use ($value): void {
                    $this->inventoryIdentityGuard->ensureIotAvailable($value);
                },
            ),
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  callable(string): bool  $isAvailable
     * @return array<string, list<string>>
     */
    private function validateIdentity(
        ?string $value,
        string $column,
        array $errors,
        callable $isAvailable,
    ): array {
        if ($value === null || $value === '') {
            return $errors;
        }

        return $isAvailable($value)
            ? $errors
            : $this->addError($errors, $column, 'duplicate');
    }

    /** @param callable(): void $ensureAvailable */
    private function identityIsAvailable(callable $ensureAvailable): bool
    {
        try {
            $ensureAvailable();

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function validateExpiryFields(array $payload, array $errors): array
    {
        if (! $this->tracksExpiry($payload)) {
            return $errors;
        }

        $expiry = $payload['expires_at'] ?? null;

        if ($expiry === null) {
            return $this->addError($errors, 'expires_at', 'required_when_tracking_expiry');
        }

        return strtotime($expiry) === false
            ? $this->addError($errors, 'expires_at', 'date')
            : $errors;
    }

    /**
     * @param  array<string, string>  $payload
     * @param  Collection<string, ProductAttribute>  $attributes
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function validateAttributes(array $payload, Collection $attributes, array $errors): array
    {
        foreach ($this->attributePayload($payload) as $code => $value) {
            $attribute = $attributes->get($code);

            if (! $attribute instanceof ProductAttribute) {
                $errors = $this->addError($errors, 'attribute_'.$code, 'unknown_or_inactive');

                continue;
            }

            if ($attribute->data_type === 'select' && ! $this->activeValueExists($attribute, $value)) {
                $errors = $this->addError($errors, 'attribute_'.$code, 'unknown_or_inactive_value');
            }
        }

        return $errors;
    }

    private function activeValueExists(ProductAttribute $attribute, string $value): bool
    {
        return ProductAttributeValue::query()
            ->where('product_attribute_id', $attribute->getKey())
            ->where('is_active', true)
            ->whereRaw('LOWER(value) = ?', [mb_strtolower($value)])
            ->exists();
    }

    /** @param array<string, string> $payload */
    public function hasInventoryData(array $payload): bool
    {
        foreach (['warehouse_code', 'quantity', 'serial_number', 'iot_number', 'lot_number', 'expires_at'] as $column) {
            if (($payload[$column] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $payload */
    public function tracksSerials(array $payload): bool
    {
        if (isset($payload['serial_number']) || isset($payload['iot_number'])) {
            return true;
        }

        if (isset($payload['track_serials'])) {
            return $this->toBool($payload['track_serials']);
        }

        $variant = isset($payload['sku'])
            ? ProductVariant::query()->where('sku', $payload['sku'])->first()
            : null;

        return $variant instanceof ProductVariant && $variant->track_serials;
    }

    /** @param array<string, string> $payload */
    public function tracksExpiry(array $payload): bool
    {
        if (isset($payload['lot_number']) || isset($payload['expires_at'])) {
            return true;
        }

        if (isset($payload['track_expiry'])) {
            return $this->toBool($payload['track_expiry']);
        }

        $variant = isset($payload['sku'])
            ? ProductVariant::query()->where('sku', $payload['sku'])->first()
            : null;

        return $variant instanceof ProductVariant && $variant->track_expiry;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, string>
     */
    public function attributePayload(array $payload): array
    {
        $attributes = [];

        foreach ($payload as $column => $value) {
            if (Str::startsWith($column, 'attribute_')) {
                $attributes[Str::after($column, 'attribute_')] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function addError(array $errors, string $column, string $error): array
    {
        $errors[$column] = [...($errors[$column] ?? []), $error];

        return $errors;
    }

    private function normalizeCode(string $code): string
    {
        return Str::snake(mb_strtolower(mb_trim($code)));
    }

    private function toBool(?string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
