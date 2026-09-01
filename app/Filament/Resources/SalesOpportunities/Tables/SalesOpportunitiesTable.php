<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Tables;

use App\Enums\SalesOpportunityStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use App\Services\Sales\QuotationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SalesOpportunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('summary')->limit(60)->searchable(),
                TextColumn::make('keywordRule.keyword')->label('Keyword')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(SalesOpportunityStatus::cases(), 'value', 'value')),
            ])
            ->recordActions([
                ViewAction::make(),
                self::decisionAction('approve', 'Approve', 'success', Heroicon::OutlinedCheckCircle),
                self::decisionAction('reject', 'Reject', 'danger', Heroicon::OutlinedXCircle),
                self::createQuotationAction(),
            ]);
    }

    /**
     * FR-025: an approved opportunity with no quotation yet may become one.
     * Visible and authorized identically — a Sales Officer or Sales Manager
     * with `sales.quotation.manage`, matching who may draft a quotation
     * directly.
     *
     * Deliberately `->action()`, not `->url()`: a `url()` callback can be
     * evaluated while the table merely renders, which would create a
     * quotation as a side effect of loading the page rather than of a click.
     * The resulting quotation is offered as a link on the success
     * notification instead of an automatic redirect.
     */
    private static function createQuotationAction(): Action
    {
        return Action::make('create_quotation')
            ->label(__('admin.sales.actions.create_quotation'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->visible(static fn (SalesOpportunity $record): bool => $record->status === SalesOpportunityStatus::Approved
                && ! $record->quotation instanceof Quotation)
            ->authorize(static fn (): bool => auth()->user() instanceof User && auth()->user()->can('create', Quotation::class))
            ->action(static function (SalesOpportunity $record): void {
                try {
                    $quotation = app(QuotationService::class)->createFromOpportunity($record);
                } catch (OpportunityNotQuotable $opportunityNotQuotable) {
                    Notification::make()->danger()->title($opportunityNotQuotable->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('admin.sales.notifications.quotation_created_from_opportunity', [
                        'number' => (string) $quotation->quotation_number,
                    ]))
                    ->actions([
                        Action::make('view')
                            ->label(__('admin.sales.actions.view_quotation'))
                            ->url(QuotationResource::getUrl('view', ['record' => $quotation])),
                    ])
                    ->send();
            });
    }

    private static function decisionAction(string $name, string $label, string $color, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon($icon)
            ->requiresConfirmation()
            ->authorize('review')
            ->visible(static fn (SalesOpportunity $record): bool => $record->status === SalesOpportunityStatus::Draft)
            ->schema([
                Textarea::make('review_notes')->label('Notes')->rows(3),
            ])
            ->action(static function (SalesOpportunity $record, array $data) use ($name): void {
                $notes = $data['review_notes'] ?? null;
                $notes = is_string($notes) ? $notes : null;

                $service = app(OpportunityReviewService::class);

                $name === 'approve' ? $service->approve($record, $notes) : $service->reject($record, $notes);
            });
    }
}
