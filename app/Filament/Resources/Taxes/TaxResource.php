<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes;

use App\Filament\AdminModuleRegistry;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\Taxes\Pages\ListTaxes;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Models\TaxRecognitionEntry;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

final class TaxResource extends Resource
{
    protected static ?string $model = TaxRecognitionEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 209;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.taxes');
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('source'))
            ->defaultSort('tax_date', 'desc')
            ->columns([
                TextColumn::make('tax_date')->date()->sortable(),
                TextColumn::make('direction')->badge()->formatStateUsing(static fn (string $state): string => self::directionLabel($state))->sortable(),
                TextColumn::make('tax_type')
                    ->label(__('Tax treatment'))
                    ->formatStateUsing(static fn (string $state): string => __($state === 'sales_tax' ? 'Sales tax' : $state))
                    ->searchable(),
                TextColumn::make('source_type')
                    ->label(__('Document'))
                    ->formatStateUsing(static fn (TaxRecognitionEntry $record): string => self::sourceDocumentLabel($record))
                    ->url(static fn (TaxRecognitionEntry $record): ?string => self::sourceDocumentUrl($record)),
                TextColumn::make('tax_amount')->money()->sortable(),
            ])
            ->filters([
                SelectFilter::make('direction')->options(self::directionOptions()),
            ]);
    }

    private static function directionLabel(string $direction): string
    {
        $label = match ($direction) {
            'input' => 'Input tax',
            'deferred_output' => 'Deferred output tax',
            'output' => 'Output',
            'output_reversal' => 'Output reversal',
            'refund' => 'Refund',
            default => Str::headline($direction),
        };

        return __($label);
    }

    /** @return array<string, string> */
    private static function directionOptions(): array
    {
        $options = [];

        foreach (['input', 'deferred_output', 'output', 'output_reversal', 'refund'] as $direction) {
            $options[$direction] = self::directionLabel($direction);
        }

        return $options;
    }

    private static function sourceDocumentLabel(TaxRecognitionEntry $record): string
    {
        $source = $record->source;

        if (! $source instanceof Model) {
            return self::documentTypeLabel((string) $record->source_type).' #'.$record->source_id;
        }

        $resource = self::sourceDocumentResource($source);
        $label = $resource !== null ? $resource::getTitleCaseModelLabel() : self::documentTypeLabel($source::class);
        $title = $resource !== null && $resource::hasRecordTitle() ? $resource::getRecordTitle($source) : null;
        $number = $source->getAttribute(Str::snake(class_basename($source)).'_number');
        $reference = match (true) {
            is_string($title) && $title !== '' => $title,
            is_string($number) && $number !== '' => $number,
            default => '#'.$record->source_id,
        };

        return $label.' '.$reference;
    }

    private static function sourceDocumentUrl(TaxRecognitionEntry $record): ?string
    {
        $source = $record->source;
        $resource = $source instanceof Model ? self::sourceDocumentResource($source) : null;

        return $resource !== null
            ? AdminModuleRegistry::resolveResourceRecordLink($resource, (int) $record->source_id)
            : null;
    }

    /** @return class-string<FilamentResource>|null */
    private static function sourceDocumentResource(Model $source): ?string
    {
        foreach (Filament::getResources() as $resource) {
            if (is_subclass_of($resource, FilamentResource::class) && $resource::getModel() === $source::class && $resource::hasPage('view')) {
                return $resource;
            }
        }

        return null;
    }

    private static function documentTypeLabel(string $class): string
    {
        $headline = Str::headline(class_basename($class));
        $translated = __($headline);

        return is_string($translated) ? $translated : $headline;
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTaxes::route('/'),
            'register' => ViewTaxRegister::route('/register'),
        ];
    }
}
