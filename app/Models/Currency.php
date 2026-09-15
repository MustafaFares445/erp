<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'is_active', 'is_default'])]
final class Currency extends Model
{
    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (Currency $currency): void {
            $currency->code = mb_strtoupper(mb_trim((string) $currency->code));
            $currency->name = mb_trim((string) $currency->name);

            if ($currency->is_default) {
                $currency->is_active = true;
            }
        });

        self::saved(static function (Currency $currency): void {
            if ($currency->is_default) {
                self::withoutEvents(static fn () => self::query()
                    ->whereKeyNot($currency->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]));

                return;
            }

            if (! self::query()->where('is_default', true)->exists()) {
                self::withoutEvents(static fn () => self::query()
                    ->whereKey($currency->getKey())
                    ->update(['is_default' => true, 'is_active' => true]));
                $currency->setAttribute('is_default', true);
                $currency->setAttribute('is_active', true);
            }
        });

        self::deleting(static function (Currency $currency): void {
            if ($currency->is_default) {
                throw new DomainException('The default currency cannot be deleted. Set another default currency first.');
            }
        });
    }
}
