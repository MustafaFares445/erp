<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit entry whenever a sensitive column of a settings row changes.
 *
 * Hooked on the model rather than the Filament form so every writer — a form,
 * a seeder, a console command, a future API — is audited the same way. Only
 * the columns named in {@see sensitiveSettingColumns()} are recorded, and only
 * their old and new values: a setting row never holds a credential.
 *
 * @mixin Model
 */
trait AuditsSensitiveSettings
{
    /** @return list<string> */
    abstract public static function sensitiveSettingColumns(): array;

    protected static function bootAuditsSensitiveSettings(): void
    {
        static::updated(static function (Model $record): void {
            $changes = [];

            foreach (static::sensitiveSettingColumns() as $column) {
                if ($record->wasChanged($column)) {
                    $changes[$column] = [
                        'old' => $record->getOriginal($column),
                        'new' => $record->getAttribute($column),
                    ];
                }
            }

            if ($changes === []) {
                return;
            }

            activity('configuration')
                ->performedOn($record)
                ->causedBy(auth()->user())
                ->withProperties(['changes' => $changes])
                ->event('updated')
                ->log('Sensitive setting changed');
        });
    }
}
