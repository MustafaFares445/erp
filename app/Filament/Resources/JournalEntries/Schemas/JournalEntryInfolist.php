<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Enums\JournalEntryStatus;
use App\Filament\AdminModuleRegistry;
use App\Models\JournalEntry;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class JournalEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('entry_number')->label(__('admin.accounting.fields.entry_number')),
                TextEntry::make('entry_date')->label(__('admin.accounting.fields.entry_date'))->date(),
                TextEntry::make('status')
                    ->label(__('admin.accounting.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (JournalEntryStatus $state): string => $state->label())
                    ->color(static fn (JournalEntryStatus $state): string => match ($state) {
                        JournalEntryStatus::Draft => 'gray',
                        JournalEntryStatus::Posted => 'success',
                    }),
                TextEntry::make('fiscalPeriod.name')
                    ->label(__('admin.accounting.fields.fiscal_period'))
                    ->placeholder(__('—')),
                // The morph carries whichever document produced the entry — or,
                // for a reversal, the entry it reverses (research.md R-003).
                TextEntry::make('source_type')
                    ->label(__('admin.accounting.fields.source'))
                    ->formatStateUsing(static fn (JournalEntry $record): string => self::sourceDocumentLabel($record))
                    ->url(static fn (JournalEntry $record): ?string => self::sourceDocumentUrl($record))
                    ->placeholder(__('—')),
                TextEntry::make('reversal.entry_number')
                    ->label(__('admin.accounting.fields.reversed_by'))
                    ->placeholder(__('—')),
                TextEntry::make('description')
                    ->label(__('admin.accounting.fields.description'))
                    ->placeholder(__('—'))
                    ->columnSpanFull(),
            ]),
            Section::make(__('admin.accounting.fields.lines'))
                ->description(fn (JournalEntry $record): string => $record->isPosted()
                    ? __('admin.accounting.hints.posted_readonly')
                    : '')
                ->schema([
                    RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                        TextEntry::make('chartAccount.code')->label(__('admin.accounting.fields.account')),
                        TextEntry::make('debit')->label(__('admin.accounting.fields.debit')),
                        TextEntry::make('credit')->label(__('admin.accounting.fields.credit')),
                        TextEntry::make('description')
                            ->label(__('admin.accounting.fields.description'))
                            ->placeholder(__('—')),
                    ]),
                ]),
        ]);
    }

    /**
     * A readable reference such as "Bill BILL-0000001" instead of the morph class name.
     */
    private static function sourceDocumentLabel(JournalEntry $record): string
    {
        $source = $record->source;
        $fallbackReference = '#'.$record->source_id;

        if (! $source instanceof Model) {
            return self::documentTypeLabel((string) $record->source_type).' '.$fallbackReference;
        }

        $resource = self::sourceDocumentResource($source);
        $label = $resource !== null ? $resource::getTitleCaseModelLabel() : self::documentTypeLabel($source::class);
        $title = $resource !== null && $resource::hasRecordTitle() ? $resource::getRecordTitle($source) : null;
        $number = $source->getAttribute(Str::snake(class_basename($source)).'_number');
        $reference = match (true) {
            is_string($title) && $title !== '' => $title,
            is_string($number) && $number !== '' => $number,
            default => $fallbackReference,
        };

        return $label.' '.$reference;
    }

    private static function sourceDocumentUrl(JournalEntry $record): ?string
    {
        $source = $record->source;
        $resource = $source instanceof Model ? self::sourceDocumentResource($source) : null;

        return $resource !== null && $record->source_id !== null
            ? AdminModuleRegistry::resolveResourceRecordLink($resource, $record->source_id)
            : null;
    }

    /**
     * The resource that owns the document's record page. Several resources may share a model
     * (Bills and Accounts Payable both use Bill), so the one with a view page is chosen.
     *
     * @return class-string<resource>|null
     */
    private static function sourceDocumentResource(Model $source): ?string
    {
        foreach (Filament::getResources() as $resource) {
            if (is_subclass_of($resource, Resource::class) && $resource::getModel() === $source::class && $resource::hasPage('view')) {
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
}
