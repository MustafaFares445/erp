<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Tables;

use App\Enums\SalesOpportunityReviewStatus;
use App\Enums\SalesOpportunityStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SalesOpportunityService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use LogicException;

final class SalesOpportunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('summary')->limit(55)->searchable(),
                TextColumn::make('customerProfile.company_name')->label('Customer')->placeholder('—')->searchable(),
                TextColumn::make('owner.name')->label('Owner')->placeholder('—'),
                TextColumn::make('estimated_value')->money()->placeholder('—')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('review_status')->label('AI review')->badge(),
                TextColumn::make('expected_close_date')->date()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(SalesOpportunityStatus::cases())->mapWithKeys(fn (SalesOpportunityStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('review_status')->options(collect(SalesOpportunityReviewStatus::cases())->mapWithKeys(fn (SalesOpportunityReviewStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(static fn (SalesOpportunity $record): bool => ! $record->status->isTerminal()),
                self::reviewAction('approve', 'Approve AI review', 'success', Heroicon::OutlinedCheckCircle),
                self::reviewAction('reject', 'Reject AI review', 'danger', Heroicon::OutlinedXCircle),
                self::stageAction('qualify', 'Qualify', SalesOpportunityStatus::Draft, 'primary', Heroicon::OutlinedArrowTrendingUp),
                self::closeAction('close_won', 'Close won', SalesOpportunityStatus::Qualified, true),
                self::closeAction('close_lost', 'Close lost', null, false),
                self::createQuotationAction(),
            ]);
    }

    private static function createQuotationAction(): Action
    {
        return Action::make('create_quotation')
            ->label(__('admin.sales.actions.create_quotation'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->visible(static fn (SalesOpportunity $record): bool => $record->isQuotable() && ! $record->quotation instanceof Quotation)
            ->authorize(static fn (): bool => auth()->user() instanceof User && auth()->user()->can('create', Quotation::class))
            ->action(static function (SalesOpportunity $record): void {
                try {
                    $quotation = app(QuotationService::class)->createFromOpportunity($record);
                } catch (OpportunityNotQuotable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    return;
                }

                Notification::make()->success()->title(__('admin.sales.notifications.quotation_created_from_opportunity', ['number' => (string) $quotation->quotation_number]))
                    ->actions([Action::make('view')->label(__('admin.sales.actions.view_quotation'))->url(QuotationResource::getUrl('view', ['record' => $quotation]))])->send();
            });
    }

    private static function reviewAction(string $name, string $label, string $color, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)->color($color)->icon($icon)->requiresConfirmation()->authorize('review')
            ->visible(static fn (SalesOpportunity $record): bool => $record->review_status === SalesOpportunityReviewStatus::Pending)
            ->schema([Textarea::make('review_notes')->label('Notes')->rows(3)])
            ->action(static function (SalesOpportunity $record, array $data) use ($name): void {
                $notes = is_string($data['review_notes'] ?? null) ? $data['review_notes'] : null;
                $service = app(OpportunityReviewService::class);
                $name === 'approve' ? $service->approve($record, $notes) : $service->reject($record, $notes);
            });
    }

    private static function stageAction(string $name, string $label, SalesOpportunityStatus $from, string $color, Heroicon $icon): Action
    {
        return Action::make($name)->label($label)->color($color)->icon($icon)->requiresConfirmation()->authorize('update')
            ->visible(static fn (SalesOpportunity $record): bool => $record->status === $from && $record->isQuotable())
            ->action(static fn (SalesOpportunity $record): SalesOpportunity => app(SalesOpportunityService::class)->qualify($record, self::actor()));
    }

    private static function closeAction(string $name, string $label, ?SalesOpportunityStatus $from, bool $won): Action
    {
        return Action::make($name)->label($label)->color($won ? 'success' : 'danger')->requiresConfirmation()->authorize('update')
            ->visible(static fn (SalesOpportunity $record): bool => ! $record->status->isTerminal() && ($from === null || $record->status === $from))
            ->schema([Textarea::make('close_reason')->required()->rows(3)])
            ->action(static function (SalesOpportunity $record, array $data) use ($won): void {
                $reason = $data['close_reason'] ?? null;
                if (! is_string($reason) || mb_trim($reason) === '') {
                    throw new LogicException('A close reason is required.');
                }
                $service = app(SalesOpportunityService::class);
                $won ? $service->closeWon($record, $reason, self::actor()) : $service->closeLost($record, $reason, self::actor());
            });
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated opportunity user is required.');
        }

        return $actor;
    }
}
