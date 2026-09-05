<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Tables;

use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use App\Services\Sales\OpportunityService;
use App\Services\Sales\QuotationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('title')->placeholder('—')->searchable(), TextColumn::make('customer.company_name')->label('Customer')->placeholder('—')->searchable(),
            TextColumn::make('stage')->badge(), TextColumn::make('estimated_value_minor')->label('Value (minor)')->numeric()->sortable(), TextColumn::make('currency'), TextColumn::make('owner.name')->label('Owner')->placeholder('—'),
            TextColumn::make('expected_close_date')->date()->sortable()->placeholder('—'), TextColumn::make('status')->label('AI review')->badge(),
            TextColumn::make('origin')->badge()
                // WP-1.10: driven by isAiOriginated() (retained origin
                // evidence) rather than by the nullable transcription FK, so
                // the badge survives the live transcript being deleted.
                ->state(static fn (SalesOpportunity $record): string => $record->isAiOriginated() ? 'AI-originated' : $record->origin->label())
                ->color(static fn (SalesOpportunity $record): string => $record->isAiOriginated() ? 'info' : 'gray'),
        ])->filters([
            SelectFilter::make('stage')->options(collect(OpportunityStage::cases())->mapWithKeys(fn (OpportunityStage $stage): array => [$stage->value => $stage->label()])->all()),
            SelectFilter::make('owner_id')->relationship('owner', 'name'),
        ])->recordActions([
            ViewAction::make(), EditAction::make()->visible(static fn (SalesOpportunity $record): bool => ! $record->stage->isClosed()),
            self::reviewAction('approve', 'Approve AI review', true), self::reviewAction('reject', 'Reject AI review', false),
            self::stageAction(), self::createQuotationAction(),
        ]);
    }

    private static function stageAction(): Action
    {
        return Action::make('change_stage')->label('Change stage')->icon(Heroicon::OutlinedArrowTrendingUp)->authorize('update')
            ->visible(static fn (SalesOpportunity $record): bool => $record->status === SalesOpportunityStatus::Approved && ! $record->stage->isClosed())
            ->schema([
                Select::make('stage')->options(collect(OpportunityStage::cases())->mapWithKeys(fn (OpportunityStage $stage): array => [$stage->value => $stage->label()])->all())->required(),
                Select::make('close_reason')->options(collect(OpportunityCloseReason::cases())->mapWithKeys(fn (OpportunityCloseReason $reason): array => [$reason->value => $reason->label()])->all()),
                Textarea::make('close_note')->rows(3),
            ])->action(static function (SalesOpportunity $record, array $data): void {
                $stage = OpportunityStage::from(self::string($data, 'stage'));
                $reason = is_string($data['close_reason'] ?? null) && $data['close_reason'] !== '' ? OpportunityCloseReason::from($data['close_reason']) : null;
                $note = is_string($data['close_note'] ?? null) ? $data['close_note'] : null;
                app(OpportunityService::class)->transitionStage($record, $stage, null, self::actor(), $reason, $note);
            });
    }

    private static function createQuotationAction(): Action
    {
        return Action::make('create_quotation')->label(__('admin.sales.actions.create_quotation'))->icon(Heroicon::OutlinedDocumentPlus)->color('primary')
            ->visible(static fn (SalesOpportunity $record): bool => $record->isQuotable() && ! $record->quotation instanceof Quotation)
            ->authorize(static fn (): bool => auth()->user() instanceof User && auth()->user()->can('create', Quotation::class))
            ->action(static function (SalesOpportunity $record): void {
                try {
                    $quotation = app(QuotationService::class)->createFromOpportunity($record);
                } catch (OpportunityNotQuotable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title(__('admin.sales.notifications.quotation_created_from_opportunity', ['number' => (string) $quotation->quotation_number]))->actions([Action::make('view')->label(__('admin.sales.actions.view_quotation'))->url(QuotationResource::getUrl('view', ['record' => $quotation]))])->send();
            });
    }

    private static function reviewAction(string $name, string $label, bool $approve): Action
    {
        return Action::make($name)->label($label)->color($approve ? 'success' : 'danger')->requiresConfirmation()->authorize('review')
            ->visible(static fn (SalesOpportunity $record): bool => $record->status === SalesOpportunityStatus::Draft)
            ->schema([Textarea::make('review_notes')->rows(3)])
            ->action(static function (SalesOpportunity $record, array $data) use ($approve): void {
                $notes = is_string($data['review_notes'] ?? null) ? $data['review_notes'] : null;
                $service = app(OpportunityReviewService::class);
                $approve ? $service->approve($record, $notes) : $service->reject($record, $notes);
            });
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new LogicException("Expected {$key}.");
        }

        return $value;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('Authenticated user required.');
        }

        return $actor;
    }
}
