<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\InventoryImportRunStatus;
use App\Enums\OperationStage;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryAlert;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\InventoryAlertService;
use App\Support\QuantityFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Explains an inventory alert: what the alert engine ({@see InventoryAlertService}) saw when it
 * raised the alert, how the subject looks now, and where to act. When the triggering condition
 * no longer holds, the alert stays open until the daily `inventory:alerts:reconcile` run (or the
 * next posting on the subject) resolves it; this diagnoser never resolves it itself.
 */
final readonly class InventoryAlertDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    /** @var array<string, string> context key => current-value key */
    private const array StockContext = [
        'on_hand_quantity' => 'on_hand',
        'reserved_quantity' => 'reserved',
        'damaged_quantity' => 'damaged',
        'available_quantity' => 'available',
        'min_quantity' => 'minimum',
    ];

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryAlert;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryAlert);

        $subject = $record->subject;
        $findings = [];

        if (! $record->isActive()) {
            $findings[] = $this->finding('alert.resolved', Category::Workflow, Severity::Notice,
                ['date' => (string) $record->resolved_at?->toDateTimeString()],
            );
        } elseif (! $subject instanceof Model) {
            $findings[] = $this->finding('alert.subject_missing', Category::DataQuality, Severity::Warning,
                ['reference' => class_basename($record->subject_type).' #'.$record->subject_id],
            );
        } else {
            $facts = $this->comparisonFacts($record, $subject);
            $stillPresent = $this->conditionStillPresent($record->type, $subject);

            $findings[] = $stillPresent === false
                ? $this->finding('alert.condition_cleared', Category::Workflow, Severity::Notice,
                    ['type' => $record->type->getLabel()], $facts, $this->subjectAction($subject))
                : $this->finding('alert.'.$record->type->value, $this->category($record->type),
                    $record->severity === InventoryAlertSeverity::Info ? Severity::Notice : Severity::Warning,
                    ['severity' => $record->severity->getLabel()], $facts, $this->subjectAction($subject));
        }

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('alert_state'),
                $this->check('alert_subject'),
                $this->check('alert_condition'),
            ],
            limits: [
                $this->limit('alert_event_based'),
                $this->limit('recorded_balances'),
            ],
        );
    }

    /**
     * Whether the condition that raised the alert still holds, using the same tests as the alert
     * engine; null when the alert records a one-off event that is never re-evaluated.
     */
    private function conditionStillPresent(InventoryAlertType $type, Model $subject): ?bool
    {
        return match (true) {
            $type === InventoryAlertType::OutOfStock && $subject instanceof InventoryStock => (float) $subject->available_quantity <= 0,
            $type === InventoryAlertType::LowStock && $subject instanceof InventoryStock => (float) $subject->available_quantity > 0
                && ($subject->replenishmentPolicy()?->isBreachedBy($subject) ?? false),
            $type === InventoryAlertType::DamagedStock && $subject instanceof InventoryStock => (float) $subject->damaged_quantity > 0,
            $type === InventoryAlertType::MissingDeviceIdentity && $subject instanceof InventoryStock => $this->deviceGap($subject) !== null,
            $type === InventoryAlertType::Expiry && $subject instanceof InventoryLot => $subject->expires_at !== null
                && $subject->totalPhysicalQuantity() > 0
                && ! $subject->expires_at->greaterThan(now()->addDays(InventorySetting::current()->expiry_alert_days)),
            $type === InventoryAlertType::TransferDiscrepancy && $subject instanceof InventoryOperation => in_array($subject->stage, [OperationStage::InTransit, OperationStage::PartiallyReceived], true),
            $type === InventoryAlertType::ImportError && $subject instanceof InventoryImportRun => in_array($subject->status, [
                InventoryImportRunStatus::ReadyWithErrors,
                InventoryImportRunStatus::Invalid,
                InventoryImportRunStatus::ConfirmedWithErrors,
                InventoryImportRunStatus::Failed,
            ], true),
            default => null,
        };
    }

    /**
     * Context snapshot taken when the alert was raised, side by side with the current value.
     *
     * @return list<string>
     */
    private function comparisonFacts(InventoryAlert $alert, Model $subject): array
    {
        $context = is_array($alert->context) ? $alert->context : [];
        $facts = [];

        if ($subject instanceof InventoryStock) {
            $policy = $subject->replenishmentPolicy();
            $current = [
                'on_hand' => (float) $subject->on_hand_quantity,
                'reserved' => (float) $subject->reserved_quantity,
                'damaged' => (float) $subject->damaged_quantity,
                'available' => (float) $subject->available_quantity,
                'minimum' => $policy instanceof WarehouseReplenishmentPolicy ? (float) $policy->min_quantity : null,
            ];

            foreach (self::StockContext as $contextKey => $currentKey) {
                if (! array_key_exists($contextKey, $context) && $current[$currentKey] === null) {
                    continue;
                }

                $facts[] = $this->fact('alert_then_now', [
                    'label' => self::contextLabel($contextKey),
                    'then' => $this->display($context[$contextKey] ?? null),
                    'now' => $this->display($current[$currentKey]),
                ]);
            }

            if ($alert->type === InventoryAlertType::MissingDeviceIdentity) {
                $gap = $this->deviceGap($subject);
                $facts[] = $this->fact('alert_then_now', [
                    'label' => __('admin.inventory.alert.context_fields.registered_devices'),
                    'then' => $this->display($context['registered_devices'] ?? null),
                    'now' => $this->display($gap['registered'] ?? $subject->on_hand_quantity),
                ]);
            }

            return $facts;
        }

        if ($subject instanceof InventoryLot) {
            $facts[] = $this->fact('alert_lot', [
                'lot' => (string) $subject->lot_number,
                'date' => $subject->expires_at?->toDateString() ?? __('—'),
            ]);
        }

        if ($subject instanceof InventoryOperation) {
            $facts[] = $this->fact('alert_operation_stage', [
                'operation' => (string) $subject->operation_number,
                'stage' => $subject->stage->label(),
            ]);
        }

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $facts[] = $this->fact('alert_context', [
                'label' => self::contextLabel((string) $key),
                'value' => $this->display($value),
            ]);
        }

        return $facts;
    }

    /**
     * @return array{physical: float, registered: int}|null
     */
    private function deviceGap(InventoryStock $stock): ?array
    {
        $variant = ProductVariant::query()->find($stock->product_variant_id);

        if (! $variant instanceof ProductVariant || ! $variant->tracksSerialsConfigured()) {
            return null;
        }

        $registered = SerializedInventoryUnit::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->where('warehouse_id', $stock->warehouse_id)
            ->whereIn('status', [SerializedInventoryUnitStatus::Available->value, SerializedInventoryUnitStatus::Damaged->value])
            ->count();
        $physical = (float) $stock->on_hand_quantity;

        return abs($physical - $registered) < 0.0005 ? null : ['physical' => $physical, 'registered' => $registered];
    }

    private function subjectAction(Model $subject): ?InventoryDiagnosticAction
    {
        return match (true) {
            $subject instanceof InventoryStock => $this->viewLink(StockLevelResource::class, $subject, 'alert_open_stock'),
            $subject instanceof InventoryLot => $this->viewLink(InventoryLotResource::class, $subject, 'open_lot'),
            $subject instanceof InventoryOperation => $this->viewLink(InventoryOperationResource::class, $subject, 'alert_open_operation'),
            $subject instanceof SerializedInventoryUnit => $this->viewLink(SerializedInventoryUnitResource::class, $subject, 'alert_open_unit'),
            $subject instanceof InventoryImportRun => $this->indexLink(InventoryImportRunResource::class, 'alert_open_imports'),
            default => null,
        };
    }

    /**
     * Known context keys have translated labels; any other key falls back to a readable headline.
     */
    private static function contextLabel(string $key): string
    {
        $translationKey = 'admin.inventory.alert.context_fields.'.$key;
        $label = __($translationKey);

        return is_string($label) && $label !== $translationKey ? $label : Str::headline($key);
    }

    private function category(InventoryAlertType $type): Category
    {
        return match ($type) {
            InventoryAlertType::LowStock => Category::Replenishment,
            InventoryAlertType::OutOfStock => Category::Availability,
            InventoryAlertType::DamagedStock => Category::Condition,
            InventoryAlertType::Expiry, InventoryAlertType::ExpiredStockReleased => Category::Expiry,
            InventoryAlertType::TransferDiscrepancy => Category::Transfer,
            InventoryAlertType::ImportError => Category::DataQuality,
            InventoryAlertType::DuplicateIdentity, InventoryAlertType::MissingDeviceIdentity => Category::Tracking,
        };
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => __('—'),
            is_int($value), is_float($value), is_numeric($value) => $this->quantity($value),
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_scalar($value) => (string) $value,
            default => __('—'),
        };
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    public static function metrics(InventoryAlert $alert): array
    {
        $end = $alert->resolved_at ?? now();
        $context = is_array($alert->context) ? $alert->context : [];
        $metrics = [
            [
                'label' => __('inventory_view_ux.ui.alert_severity'),
                'value' => $alert->severity->getLabel(),
                'tone' => match ($alert->severity) {
                    InventoryAlertSeverity::Critical => 'danger',
                    InventoryAlertSeverity::Warning => 'warning',
                    InventoryAlertSeverity::Info => null,
                },
            ],
            [
                'label' => __('inventory_view_ux.ui.alert_age'),
                'value' => (string) (int) $alert->created_at?->diffInDays($end, true),
                'helper' => $alert->isActive() ? __('inventory_view_ux.ui.alert_open') : __('inventory_view_ux.ui.alert_resolved'),
            ],
        ];

        foreach (array_slice(array_filter($context, static fn (mixed $value): bool => is_int($value) || is_float($value)), 0, 4, true) as $key => $value) {
            $metrics[] = [
                'label' => self::contextLabel((string) $key),
                'value' => QuantityFormatter::display($value),
                'helper' => __('inventory_view_ux.ui.alert_when_raised'),
            ];
        }

        return $metrics;
    }
}
