<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns;

use App\Models\Concerns\Favoritable;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Per-user star toggle for {@see Favoritable} models.
 *
 * The starred flag is loaded with the table query as an `is_favorited`
 * existence check, so the column costs no extra query per row.
 */
final class FavoriteColumn extends IconColumn
{
    #[\Override]
    public static function make(?string $name = 'is_favorited'): static
    {
        return parent::make($name);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Favorite'))
            ->toggleable(false)
            ->alignCenter()
            ->width('1%')
            ->boolean()
            ->trueIcon(Heroicon::Star)
            ->falseIcon(Heroicon::OutlinedStar)
            ->trueColor('warning')
            ->falseColor('gray')
            ->tooltip(static fn (bool $state): string => $state ? __('Remove from favorites') : __('Add to favorites'))
            ->action(static function (Model $record): void {
                $user = auth()->user();

                abort_unless($user instanceof User && $record instanceof Favoritable, 403);

                $favorited = $record->toggleFavoriteFor($user);
                $record->setAttribute('is_favorited', $favorited);
            });
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    #[\Override]
    public function applyRelationshipAggregates(Builder|Relation $query): Builder|Relation
    {
        return parent::applyRelationshipAggregates($query)->withExists([
            'favorites as is_favorited' => static fn (Builder $favorite): Builder => $favorite->where('user_id', auth()->id()),
        ]);
    }
}
