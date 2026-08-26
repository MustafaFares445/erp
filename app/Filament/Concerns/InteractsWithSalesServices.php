<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Forces every Sales Filament action to be a thin adapter over a domain
 * service, mirroring {@see InteractsWithAccountingServices} and
 * {@see InteractsWithPurchasingServices}.
 *
 * The methods are **static** so the same runner serves page header actions,
 * table actions, and static resource configurations, which have no `$this`.
 *
 * On failure this notifies with the domain exception's own message, then
 * throws {@see Halt} so Filament stops without also emitting its generic
 * notification. It performs no writes and enforces no rules itself: the
 * service it wraps owns its transaction, so a throw leaves no partial state.
 */
trait InteractsWithSalesServices
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @param  array<string, bool|float|int|string|null>  $successReplacements
     * @return TReturn
     */
    protected static function runSalesOperation(
        callable $operation,
        ?string $successMessageKey = null,
        array $successReplacements = [],
    ): mixed {
        try {
            $result = $operation();
        } catch (ValidationException $exception) {
            self::notifySalesFailure(implode(' ', $exception->validator->errors()->all()));

            throw new Halt($exception->getMessage(), $exception->getCode(), $exception);
        } catch (DomainException $exception) {
            self::notifySalesFailure($exception->getMessage());

            throw new Halt($exception->getMessage(), $exception->getCode(), $exception);
        }

        if ($successMessageKey !== null) {
            Notification::make()
                ->success()
                ->title(__($successMessageKey, $successReplacements))
                ->send();
        }

        return $result;
    }

    /**
     * The acting user, or null when there is none.
     *
     * Every Sales service takes an explicit actor rather than resolving one
     * internally, so the Filament layer is where the authenticated user is
     * read and handed over (FR-077).
     */
    protected static function salesActor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    /**
     * Filament hands a page's form state over untyped while every Sales
     * service takes precise types. These helpers turn one into the other in
     * the one place allowed to know both, so no page repeats the narrowing
     * and no service loosens a signature to accommodate a form — mirroring
     * {@see InteractsWithPurchasingServices}.
     */
    protected static function stringFrom(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    protected static function nullableStringFrom(mixed $value): ?string
    {
        $string = self::stringFrom($value);

        return $string === '' ? null : $string;
    }

    protected static function integerFrom(mixed $value): int
    {
        return self::nullableIntegerFrom($value) ?? 0;
    }

    protected static function nullableIntegerFrom(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function floatFrom(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected static function nullableFloatFrom(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Normalises a repeater field's raw state into the shape every priced
     * line-taking Sales service expects. `unit_price` and `tax_amount` stay
     * `null` when the form field was left blank, rather than becoming `0.0`,
     * so the service can tell "no override given" apart from "overridden to
     * zero" (FR-015, FR-017).
     *
     * @return list<array{product_variant_id: int, quantity: float, unit_price?: float|null, tax_amount?: float|null, description?: string|null}>
     */
    protected static function normalizeLines(mixed $rawLines): array
    {
        if (! is_array($rawLines)) {
            return [];
        }

        $lines = [];

        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                continue;
            }

            $lines[] = [
                'product_variant_id' => self::integerFrom($rawLine['product_variant_id'] ?? null),
                'quantity' => self::floatFrom($rawLine['quantity'] ?? null),
                'unit_price' => self::nullableFloatFrom($rawLine['unit_price'] ?? null),
                'tax_amount' => self::nullableFloatFrom($rawLine['tax_amount'] ?? null),
                'description' => self::nullableStringFrom($rawLine['description'] ?? null),
            ];
        }

        return $lines;
    }

    private static function notifySalesFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }
}
