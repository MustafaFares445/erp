<?php

declare(strict_types=1);

namespace App\Filament\Resources\Interactions;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\Interactions\Pages\ListInteractions;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Lead;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class InteractionResource extends Resource
{
    protected static ?string $model = Interaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.crm';

    protected static ?int $navigationSort = 503;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Interactions');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('subject'))
            ->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('subject_type')->label(__('Party type'))->formatStateUsing(static fn (string $state): string => self::partyTypeLabel($state)),
            TextColumn::make('party')->label(__('Party'))->wrap()
                ->state(static fn (Interaction $record): ?string => self::partyName($record))
                ->placeholder(__('—')),
            TextColumn::make('type')->badge(),
            TextColumn::make('direction')->badge(),
            TextColumn::make('summary')->searchable()->wrap(),
            TextColumn::make('employee.name')->label(__('Employee'))->searchable(),
            TextColumn::make('outcome')->badge()->placeholder(__('—')),
        ]);
    }

    /**
     * Human label for the morph class of the interaction's party.
     */
    public static function partyTypeLabel(string $subjectType): string
    {
        return match ($subjectType) {
            CustomerProfile::class => __('Customer'),
            Lead::class => __('Lead'),
            default => __(class_basename($subjectType)),
        };
    }

    /**
     * Human name of the interaction's party (customer company or lead), resolved through the morph relation.
     */
    public static function partyName(Interaction $interaction): ?string
    {
        $subject = $interaction->subject;

        return match (true) {
            $subject instanceof CustomerProfile => $subject->company_name,
            $subject instanceof Lead => $subject->displayName(),
            default => null,
        };
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ListInteractions::route('/')];
    }
}
