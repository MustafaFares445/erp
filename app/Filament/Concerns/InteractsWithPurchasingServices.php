<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Forces every purchasing Filament action to be a thin adapter over a domain
 * service, mirroring {@see InteractsWithAccountingServices}.
 *
 * The methods are **static** so the same runner serves both page header actions
 * and the static table and relation-manager configurations, which have no
 * `$this`.
 *
 * On failure this notifies with the domain exception's own message — which is
 * why the purchasing exceptions name the offending order, line, or supplier —
 * then throws {@see Halt} so Filament stops without also emitting its generic
 * notification. It performs no writes and enforces no rules itself: the service
 * it wraps owns its transaction, so a throw leaves no partial state.
 *
 * @see /specs/017-purchasing-orders-suppliers/contracts/permissions.md R-G
 */
trait InteractsWithPurchasingServices
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @param  array<string, bool|float|int|string|null>  $successReplacements
     * @return TReturn
     */
    protected static function runPurchasingOperation(
        callable $operation,
        ?string $successMessageKey = null,
        array $successReplacements = [],
    ): mixed {
        try {
            $result = $operation();
        } catch (ValidationException $exception) {
            self::notifyPurchasingFailure(implode(' ', $exception->validator->errors()->all()));

            throw new Halt($exception->getMessage(), $exception->getCode(), $exception);
        } catch (DomainException $exception) {
            self::notifyPurchasingFailure($exception->getMessage());

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
     * Every purchasing service takes an explicit actor rather than resolving one
     * internally, so the Filament layer is where the authenticated user is read
     * and handed over.
     */
    protected static function purchasingActor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    /**
     * Filament hands a page's form state over untyped while every purchasing
     * service takes precise types. These helpers turn one into the other in the
     * one place allowed to know both, so no page repeats the narrowing and no
     * service loosens a signature to accommodate a form.
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

    private static function notifyPurchasingFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('admin.purchasing.notifications.failed'))
            ->body($message)
            ->send();
    }
}
