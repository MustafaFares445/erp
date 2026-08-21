<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Resources\StockLevels\Actions\StockDamageActions;
use App\Models\User;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Forces every ledger-affecting Filament action to be a thin adapter over a
 * domain service, mirroring {@see InteractsWithInventoryServices}.
 *
 * The methods are **static** so the same runner serves both page header actions
 * and the static table/relation-manager configurations, which have no `$this`.
 *
 * On failure this notifies with the domain exception's own message — which is
 * why the accounting exceptions carry specific context — and then throws
 * {@see Halt} so Filament stops without also emitting its generic success or
 * failure notification. It performs no writes and enforces no rules itself: the
 * service it wraps owns its transaction, so a throw leaves no partial state.
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/journal-posting.md
 */
trait InteractsWithAccountingServices
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @param  array<string, bool|float|int|string|null>  $successReplacements
     * @return TReturn
     */
    protected static function runAccountingOperation(
        callable $operation,
        ?string $successMessageKey = null,
        array $successReplacements = [],
    ): mixed {
        try {
            $result = $operation();
        } catch (ValidationException $exception) {
            self::notifyAccountingFailure(implode(' ', $exception->validator->errors()->all()));

            throw new Halt($exception->getMessage(), $exception->getCode(), $exception);
        } catch (DomainException $exception) {
            self::notifyAccountingFailure($exception->getMessage());

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
     * Every accounting service takes an explicit actor rather than resolving one
     * internally (research.md R-010), so the Filament layer is where the
     * authenticated user is read and handed over.
     */
    protected static function accountingActor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    /**
     * Filament hands a page's form state over untyped, while every accounting
     * service takes precise types. The five helpers below turn one into the other
     * in the one place that is allowed to know both — an adapter's whole job — so
     * no page repeats the narrowing and no service loosens a signature to
     * accommodate a form. `StockDamageActions` narrows its own action data the
     * same way.
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

    protected static function booleanFrom(mixed $value, bool $default = false): bool
    {
        return is_scalar($value) ? (bool) $value : $default;
    }

    private static function notifyAccountingFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('admin.accounting.notifications.failed'))
            ->body($message)
            ->send();
    }
}
