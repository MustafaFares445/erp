<?php

declare(strict_types=1);

namespace App\Filament\Resources\PricingTiers;

use App\Data\Inventory\PricingTierData;
use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Enums\CrmPermission;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Enums\ProductStatus;
use App\Enums\SystemPermission;
use App\Enums\UserType;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Models\ConstraintOverride;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Inventory\PricingTierService;
use App\Services\Settings\BusinessConstraintService;
use App\Services\Settings\ConstraintGuard;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use LogicException;

final class PricingTierResource extends Resource
{
    protected static ?string $model = PricingTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Pricing Tiers';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(150)->unique(PricingTier::class, 'name', ignoreRecord: true),
            Select::make('tier_type')
                ->label('Tier type')
                ->options(self::tierTypeOptions())
                ->default(PricingTierType::General->value)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $set('discount_type', PricingTierDiscountType::Percentage->value);
                    $set('customer_user_id', null);
                    $set('visibility', $state === PricingTierType::ProductScoped->value ? PricingTierVisibility::Public->value : null);
                    $set('valid_from', null);
                    $set('valid_until', null);

                    if ($state === PricingTierType::ProductScoped->value) {
                        $set('is_active', false);
                    }
                }),
            Select::make('discount_type')
                ->label('Discount type')
                ->options(fn (Get $get): array => $get('tier_type') === PricingTierType::ProductScoped->value
                    ? self::discountTypeOptions()
                    : [PricingTierDiscountType::Percentage->value => 'Percentage'])
                ->default(PricingTierDiscountType::Percentage->value)
                ->required()
                ->live(),
            self::discountValueField(),
            self::discountApprovalReasonField(),
            Select::make('customer_user_id')
                ->label('Customer')
                ->options(self::customerOptions(...))
                ->searchable()
                ->preload()
                ->required(fn (Get $get): bool => $get('tier_type') === PricingTierType::CustomerSpecific->value)
                ->visible(fn (Get $get): bool => $get('tier_type') === PricingTierType::CustomerSpecific->value),
            Select::make('visibility')
                ->options(self::visibilityOptions())
                ->required(fn (Get $get): bool => $get('tier_type') === PricingTierType::ProductScoped->value)
                ->visible(fn (Get $get): bool => $get('tier_type') === PricingTierType::ProductScoped->value),
            DatePicker::make('valid_from')
                ->label('Valid from')
                ->visible(fn (Get $get): bool => $get('tier_type') === PricingTierType::ProductScoped->value),
            DatePicker::make('valid_until')
                ->label('Valid until')
                ->afterOrEqual('valid_from')
                ->visible(fn (Get $get): bool => $get('tier_type') === PricingTierType::ProductScoped->value),
            Toggle::make('is_active')->label('Active')->default(true),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('tier_type')->label('Type')->formatStateUsing(fn (PricingTier $record): string => self::tierTypeOptions()[$record->tier_type->value])->badge()->sortable(),
                TextColumn::make('discount')->state(fn (PricingTier $record): string => $record->discount_type === PricingTierDiscountType::Percentage
                    ? $record->discount_value.'%'
                    : '$'.$record->discount_value),
                TextColumn::make('customer.name')->label('Specific customer')->placeholder('—')->searchable(),
                TextColumn::make('visibility')->formatStateUsing(fn (mixed $state): string => $state instanceof PricingTierVisibility ? $state->value : '—')->badge(),
                TextColumn::make('status')->state(fn (PricingTier $record): string => ucfirst($record->status()))->badge(),
                TextColumn::make('valid_from')->date()->placeholder('—')->sortable(),
                TextColumn::make('valid_until')->date()->placeholder('—')->sortable(),
                TextColumn::make('products_count')->label('Products')->sortable(),
                TextColumn::make('active_assignments_count')->label('Active customers')->sortable(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('tier_type')->label('Type')->options(self::tierTypeOptions()),
                SelectFilter::make('visibility')->options(self::visibilityOptions()),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('status')
                    ->schema([Select::make('value')->options(['scheduled' => 'Scheduled', 'current' => 'Current', 'expired' => 'Expired'])])
                    ->query(fn (Builder $query, array $data): Builder => self::applyStatusFilter($query, $data['value'] ?? null)),
                Filter::make('near_expiry')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(function (Builder $query, array $data): Builder {
                        if (is_string($data['from'] ?? null)) {
                            $query->whereDate('valid_until', '>=', $data['from']);
                        }

                        if (is_string($data['until'] ?? null)) {
                            $query->whereDate('valid_until', '<=', $data['until']);
                        }

                        return $query;
                    }),
                SelectFilter::make('product')->relationship('products', 'name')->searchable()->preload(),
                SelectFilter::make('customer')
                    ->options(self::customerOptions(...))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $customerQuery): Builder => $customerQuery
                            ->where('customer_user_id', $data['value'])
                            ->orWhereHas('assignments', fn (Builder $assignmentQuery): Builder => $assignmentQuery
                                ->where('customer_user_id', $data['value'])
                                ->where('is_active', true)))
                        : $query),
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::editAction(),
                self::editDiscountAction(),
                self::manageProductsAction(),
                self::manageCustomersAction(),
                self::previewAction(),
                Action::make('activate')
                    ->visible(fn (PricingTier $record): bool => ! $record->is_active && self::canManage())
                    ->requiresConfirmation()
                    ->action(fn (PricingTier $record, PricingTierService $service): PricingTier => $service->activate($record, self::actor())),
                Action::make('deactivate')
                    ->visible(fn (PricingTier $record): bool => $record->is_active && self::canManage())
                    ->requiresConfirmation()
                    ->action(fn (PricingTier $record, PricingTierService $service): PricingTier => $service->deactivate($record, self::actor())),
                DeleteAction::make()
                    ->visible(self::canManage(...))
                    ->using(fn (PricingTier $record, PricingTierService $service): bool => $service->delete($record, self::actor())),
                RestoreAction::make()
                    ->visible(self::canRestoreTier(...))
                    ->using(fn (PricingTier $record, PricingTierService $service): PricingTier => $service->restore($record, self::actor())),
            ])
            ->paginated([25, 50]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->visible(self::canManage(...))
            ->using(fn (array $data, PricingTierService $service): Model => self::runPricingOperation(
                fn (): Model => $service->save(null, self::data($data), self::actor(), self::discountApproval($data)),
            ));
    }

    public static function assignGeneralTierAction(): Action
    {
        return Action::make('assignGeneralTier')
            ->label('Assign general tier')
            ->visible(self::canManageLinks(...))
            ->schema([
                Select::make('customer_user_id')->label('Customer')->options(self::customerOptions(...))->searchable()->required(),
                Select::make('pricing_tier_id')
                    ->label('General tier')
                    ->options(fn (): array => PricingTier::query()->current()->where('tier_type', PricingTierType::General)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, PricingTierService $service): void {
                $customer = User::query()->findOrFail(self::requiredInteger($data, 'customer_user_id'));
                $tier = PricingTier::query()->findOrFail(self::requiredInteger($data, 'pricing_tier_id'));
                $service->assignGeneralTier($customer, $tier, self::actor());
                Notification::make()->title('General pricing tier assigned.')->success()->send();
            });
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->visible(self::canManage(...))
            ->using(fn (PricingTier $record, array $data, PricingTierService $service): Model => self::runPricingOperation(
                fn (): Model => $service->save($record, self::data($data), self::actor(), self::discountApproval($data, $record)),
            ));
    }

    private static function editDiscountAction(): Action
    {
        return Action::make('editDiscount')
            ->label('Edit discount')
            ->visible(fn (): bool => self::canEditDiscount() && ! self::canManage())
            ->fillForm(fn (PricingTier $record): array => [
                'discount_type' => $record->discount_type->value,
                'discount_value' => $record->discount_value,
            ])
            ->schema([
                Select::make('discount_type')->options(self::discountTypeOptions())->required()->live(),
                self::discountValueField(),
                self::discountApprovalReasonField(),
            ])
            ->action(fn (PricingTier $record, array $data, PricingTierService $service): PricingTier => self::runPricingOperation(
                fn (): PricingTier => $service->save(
                    $record,
                    new PricingTierData(
                        name: $record->name,
                        tierType: $record->tier_type,
                        discountType: PricingTierDiscountType::from(self::stringValue($data, 'discount_type')),
                        discountValue: self::floatValue($data, 'discount_value'),
                        customerUserId: $record->customer_user_id,
                        visibility: $record->visibility,
                        validFrom: $record->valid_from?->toDateString(),
                        validUntil: $record->valid_until?->toDateString(),
                        isActive: $record->is_active,
                    ),
                    self::actor(),
                    self::discountApproval($data, $record),
                ),
            ));
    }

    private static function manageProductsAction(): Action
    {
        return Action::make('manageProducts')
            ->label('Manage products')
            ->visible(fn (PricingTier $record): bool => $record->tier_type === PricingTierType::ProductScoped && self::canManageLinks())
            ->fillForm(fn (PricingTier $record): array => ['product_ids' => $record->products()->pluck('products.id')->all()])
            ->schema([
                Select::make('product_ids')->label('Products')->multiple()->options(fn (): array => Product::query()
                    ->where('is_active', true)->where('status', ProductStatus::Active)->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload(),
            ])
            ->action(fn (PricingTier $record, array $data, PricingTierService $service): PricingTier => $service->syncProducts(
                $record,
                self::integerList($data, 'product_ids'),
                self::actor(),
            ));
    }

    private static function manageCustomersAction(): Action
    {
        return Action::make('manageCustomers')
            ->label('Manage customers')
            ->visible(fn (PricingTier $record): bool => $record->tier_type === PricingTierType::ProductScoped && self::canManageLinks())
            ->fillForm(fn (PricingTier $record): array => ['customer_user_ids' => $record->assignments()->where('is_active', true)->pluck('customer_user_id')->all()])
            ->schema([Select::make('customer_user_ids')->label('Customers')->multiple()->options(self::customerOptions(...))->searchable()->preload()])
            ->action(fn (PricingTier $record, array $data, PricingTierService $service): PricingTier => $service->syncCustomers(
                $record,
                self::integerList($data, 'customer_user_ids'),
                self::actor(),
            ));
    }

    private static function previewAction(): Action
    {
        return Action::make('previewPrice')
            ->label('Preview price')
            ->visible(self::canPreview(...))
            ->schema([
                Select::make('customer_user_id')->label('Customer')->options(self::customerOptions(...))->searchable()->required(),
                Select::make('product_variant_id')->label('Variant')->options(fn (): array => ProductVariant::query()
                    ->where('is_active', true)->where('status', ProductStatus::Active)->orderBy('sku')->pluck('sku', 'id')->all())->searchable()->required(),
            ])
            ->action(function (array $data, PriceResolver $resolver): void {
                $customer = User::query()->findOrFail(self::requiredInteger($data, 'customer_user_id'));
                $variant = ProductVariant::query()->findOrFail(self::requiredInteger($data, 'product_variant_id'));
                $price = $resolver->resolve($variant, $customer);
                $notification = Notification::make()
                    ->title('Resolved price: '.number_format($price->amount, 2))
                    ->body(sprintf(
                        'Base: %s · Source: %s · Tier: %s · Discount: %s · Floor: %s',
                        number_format($price->baseAmount, 2),
                        $price->source->value,
                        $price->pricingTier instanceof PricingTier ? $price->pricingTier->name : 'Base price',
                        number_format($price->discountAmount, 2),
                        $price->isBelowFloor ? 'Below minimum' : 'Allowed',
                    ));

                if ($price->isBelowFloor) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManagePricingTiers::route('/')];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('customer:id,name')
            ->withCount([
                'products',
                'assignments as active_assignments_count' => fn (Builder $query): Builder => $query->where('is_active', true),
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * Turn a domain refusal into a notification instead of a 500.
     *
     * Mirrors {@see InteractsWithSalesServices}: notify
     * with the domain message, then halt so Filament stops without adding its
     * own generic error on top. Written here rather than reusing that trait
     * because this is a pricing surface and borrows the inventory error title.
     *
     * Only DomainException is caught. PricingTierService converts its own
     * QueryException and raises nothing else, so a second catch arm would be
     * unreachable.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    private static function runPricingOperation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException $domainException) {
            self::notifyPricingFailure($domainException->getMessage());

            throw new Halt($domainException->getMessage(), $domainException->getCode(), $domainException);
        }
    }

    private static function notifyPricingFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('admin.inventory.notifications.error'))
            ->body($message)
            ->send();
    }

    /**
     * The discount input, shared by the full form and the discount-only action
     * so the two cannot disagree about the ceiling. They did before: the action
     * carried no upper bound at all.
     */
    private static function discountValueField(): TextInput
    {
        return TextInput::make('discount_value')
            ->label('Discount value')
            ->numeric()
            ->minValue(fn (Get $get): float => $get('discount_type') === PricingTierDiscountType::Fixed->value ? 0.01 : 0.0)
            ->maxValue(self::discountMaxValue(...))
            ->helperText(self::discountHelperText(...))
            ->step(0.01)
            ->live(onBlur: true)
            ->required();
    }

    /**
     * A reason is asked for only when one is needed: the entered percentage is
     * past a ceiling that can be approved past, and this user may approve it.
     * Everyone else gets the refusal instead.
     */
    private static function discountApprovalReasonField(): Textarea
    {
        return Textarea::make('discount_approval_reason')
            ->label(__('admin.constraints.fields.approval_reason'))
            ->helperText(__('admin.constraints.fields.approval_reason_hint'))
            ->rows(2)
            ->visible(self::needsDiscountApproval(...))
            ->required(self::needsDiscountApproval(...));
    }

    /**
     * Only a ceiling that blocks belongs in the browser.
     *
     * Under the approval or warn modes the value has to reach the service,
     * which is the only thing that knows whether an approval exists. Capping it
     * here would silently refuse a discount the business has decided is
     * approvable, and the operator would never learn why.
     */
    private static function discountMaxValue(Get $get): ?float
    {
        if ($get('discount_type') !== PricingTierDiscountType::Percentage->value) {
            return null;
        }

        $constraint = app(ConstraintGuard::class)->constraint(BusinessConstraintKey::MaxDiscountPercent);

        return $constraint->requireEnforcement() === BusinessConstraintEnforcement::Block
            ? $constraint->value
            : 100.0;
    }

    private static function discountHelperText(Get $get): string
    {
        $constraint = app(ConstraintGuard::class)->constraint(BusinessConstraintKey::MaxDiscountPercent);
        $replacements = ['limit' => self::formatPercent($constraint->requireValue())];

        if ($get('discount_type') === PricingTierDiscountType::Fixed->value) {
            return __('admin.constraints.hints.fixed_ceiling', $replacements);
        }

        return match ($constraint->requireEnforcement()) {
            BusinessConstraintEnforcement::Block => __('admin.constraints.hints.ceiling_blocks', $replacements),
            BusinessConstraintEnforcement::RequireApproval => __('admin.constraints.hints.ceiling_approval', $replacements),
            BusinessConstraintEnforcement::Warn => __('admin.constraints.hints.ceiling_warn', $replacements),
        };
    }

    /**
     * The record is consulted, not just the entered value: a tier whose
     * discount is already approved must not be asked to justify itself again
     * every time something unrelated about it is edited.
     */
    private static function needsDiscountApproval(Get $get, ?Model $record = null): bool
    {
        if ($get('discount_type') !== PricingTierDiscountType::Percentage->value) {
            return false;
        }

        $value = $get('discount_value');
        $constraint = app(ConstraintGuard::class)->constraint(BusinessConstraintKey::MaxDiscountPercent);

        if (! is_numeric($value)
            || $constraint->value === null
            || (float) $value <= $constraint->value + BusinessConstraintKey::ValueTolerance
            || ! $constraint->requireEnforcement()->acceptsOverride()
            || ! self::canApproveConstraint()) {
            return false;
        }

        return ! self::standingDiscountApproval(
            $record instanceof PricingTier ? $record : null,
            (float) $value,
        ) instanceof ConstraintOverride;
    }

    private static function canApproveConstraint(): bool
    {
        return self::actorHasAny(SystemPermission::ConstraintManage->value);
    }

    /**
     * Find the approval this save needs, or record a new one.
     *
     * An approval already on file for this tier at this exact discount still
     * stands. Without that, renaming or re-saving an approved tier would demand
     * a fresh reason every time, which trains people to type one without
     * reading it and buries the approvals that matter.
     *
     * A newly minted approval is granted and spent in the same act, so no
     * approved-but-unused row is left lying around for a later, different
     * discount to pick up.
     *
     * @param  array<mixed>  $data
     */
    private static function discountApproval(array $data, ?PricingTier $record = null): ?ConstraintOverride
    {
        $value = self::floatValue($data, 'discount_value');
        $standing = self::standingDiscountApproval($record, $value);

        if ($standing instanceof ConstraintOverride) {
            return $standing;
        }

        $reason = $data['discount_approval_reason'] ?? null;

        if (! is_string($reason) || mb_trim($reason) === '' || ! self::canApproveConstraint()) {
            return null;
        }

        return app(BusinessConstraintService::class)->approveOverride(
            BusinessConstraintKey::MaxDiscountPercent,
            $value,
            $reason,
            self::actor(),
            $record,
        );
    }

    private static function standingDiscountApproval(?PricingTier $record, float $value): ?ConstraintOverride
    {
        if (! $record instanceof PricingTier) {
            return null;
        }

        $tolerance = BusinessConstraintKey::ValueTolerance;

        return ConstraintOverride::query()
            ->where('constraint_key', BusinessConstraintKey::MaxDiscountPercent->value)
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->whereBetween('attempted_value', [$value - $tolerance, $value + $tolerance])
            ->latest('approved_at')
            ->first();
    }

    private static function formatPercent(float $value): string
    {
        return mb_rtrim(mb_rtrim(number_format($value, 2, '.', ''), '0'), '.').'%';
    }

    /** @param array<mixed> $data */
    private static function data(array $data): PricingTierData
    {
        return new PricingTierData(
            name: self::stringValue($data, 'name'),
            tierType: PricingTierType::from(self::stringValue($data, 'tier_type', PricingTierType::General->value)),
            discountType: PricingTierDiscountType::from(self::stringValue($data, 'discount_type', PricingTierDiscountType::Percentage->value)),
            discountValue: self::floatValue($data, 'discount_value'),
            customerUserId: self::integerValue($data, 'customer_user_id'),
            visibility: ($visibility = self::stringValue($data, 'visibility')) === '' ? null : PricingTierVisibility::from($visibility),
            validFrom: ($validFrom = self::stringValue($data, 'valid_from')) === '' ? null : $validFrom,
            validUntil: ($validUntil = self::stringValue($data, 'valid_until')) === '' ? null : $validUntil,
            isActive: self::booleanValue($data, 'is_active'),
        );
    }

    /**
     * @param  Builder<PricingTier>  $query
     * @return Builder<PricingTier>
     */
    private static function applyStatusFilter(Builder $query, mixed $status): Builder
    {
        if ($status === 'scheduled') {
            return $query->where('is_active', true)->whereDate('valid_from', '>', today());
        }

        if ($status === 'current') {
            return $query
                ->where('is_active', true)
                ->where(fn (Builder $dates): Builder => $dates->whereNull('valid_from')->orWhereDate('valid_from', '<=', today()))
                ->where(fn (Builder $dates): Builder => $dates->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
        }

        if ($status === 'expired') {
            return $query->where('is_active', true)->whereDate('valid_until', '<', today());
        }

        return $query;
    }

    /** @param array<mixed> $data */
    private static function stringValue(array $data, string $key, string $default = ''): string
    {
        return Arr::string($data, $key, $default);
    }

    /** @param array<mixed> $data */
    private static function floatValue(array $data, string $key): float
    {
        $value = Arr::get($data, $key, 0.0);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @param array<mixed> $data */
    private static function integerValue(array $data, string $key): ?int
    {
        $value = Arr::get($data, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<mixed> $data */
    private static function requiredInteger(array $data, string $key): int
    {
        $value = Arr::get($data, $key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<int>
     */
    private static function integerList(array $data, string $key): array
    {
        return array_values(array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            Arr::array($data, $key, []),
        ));
    }

    /** @param array<mixed> $data */
    private static function booleanValue(array $data, string $key): bool
    {
        return Arr::boolean($data, $key, false);
    }

    /** @return array<string, string> */
    private static function tierTypeOptions(): array
    {
        return [
            PricingTierType::General->value => 'General',
            PricingTierType::CustomerSpecific->value => 'Customer-specific',
            PricingTierType::ProductScoped->value => 'Product-scoped',
        ];
    }

    /** @return array<string, string> */
    private static function discountTypeOptions(): array
    {
        return [PricingTierDiscountType::Percentage->value => 'Percentage', PricingTierDiscountType::Fixed->value => 'Fixed amount'];
    }

    /** @return array<string, string> */
    private static function visibilityOptions(): array
    {
        return [PricingTierVisibility::Public->value => 'Public', PricingTierVisibility::Restricted->value => 'Restricted'];
    }

    /** @return array<int, string> */
    private static function customerOptions(): array
    {
        $customers = User::query()
            ->where('user_type', UserType::Customer)
            ->whereHas('customerProfile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name']);
        $options = [];

        foreach ($customers as $customer) {
            $id = $customer->getKey();

            if (is_int($id)) {
                $options[$id] = $customer->name;
            }
        }

        return $options;
    }

    public static function canManage(): bool
    {
        return self::actorCan(CrmPermission::PricingTierManage, InventoryPermission::PricingManage);
    }

    public static function canManageLinks(): bool
    {
        return self::actorCan(CrmPermission::PricingTierLinkManage, InventoryPermission::PricingManage);
    }

    private static function canEditDiscount(): bool
    {
        return self::actorCan(CrmPermission::PricingTierDiscountManage, InventoryPermission::PricingManage);
    }

    private static function canPreview(): bool
    {
        return self::actorCan(CrmPermission::PricePreview, InventoryPermission::PricingView);
    }

    private static function canRestoreTier(): bool
    {
        return self::actorCan(CrmPermission::PricingTierRestore, InventoryPermission::PricingManage);
    }

    private static function actorCan(CrmPermission $permission, InventoryPermission $inventoryPermission): bool
    {
        return self::actorHasAny($permission->value, $inventoryPermission->value);
    }

    /**
     * Whether the current user holds any one of these permissions.
     *
     * The admin bypass narrows the moment an admin also holds a scoped
     * dashboard role, which is the rule every module here follows.
     */
    private static function actorHasAny(string ...$permissions): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->isAdmin() && ! $actor->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return array_any($permissions, fn (string $permission) => $actor->can($permission));
    }

    public static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated pricing actor is required.');
        }

        return $actor;
    }
}
