<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations\RelationManagers;

use App\Enums\BonusSuggestionStatus;
use App\Models\BonusSuggestion;
use App\Models\EmployeeSalaryCalculation;
use App\Services\Employees\BonusApprovalService;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class BonusSuggestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'bonusSuggestions';

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.bonus_suggestions');
    }

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')->numeric()->prefix('AED')->required(),
                Textarea::make('reason')->required(),
            ]);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->columns([
                TextColumn::make('amount')->money('AED'),
                TextColumn::make('reason')->limit(60),
                TextColumn::make('status')->badge(),
                TextColumn::make('approvedBy.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('approved_at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->using($this->createSuggestion(...)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(static fn (BonusSuggestion $record): bool => $record->status === BonusSuggestionStatus::Pending),
                self::decisionAction('approve', 'Approve', 'success', Heroicon::OutlinedCheckCircle),
                self::decisionAction('reject', 'Reject', 'danger', Heroicon::OutlinedXCircle),
                DeleteAction::make()
                    ->visible(static fn (BonusSuggestion $record): bool => $record->status === BonusSuggestionStatus::Pending),
            ]);
    }

    private static function decisionAction(string $name, string $label, string $color, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon($icon)
            ->requiresConfirmation()
            ->visible(static fn (BonusSuggestion $record): bool => $record->status === BonusSuggestionStatus::Pending)
            ->schema([
                Textarea::make('decision_notes')->label('Notes'),
            ])
            ->action(static function (BonusSuggestion $record, array $data) use ($name): void {
                $notes = $data['decision_notes'] ?? null;
                $notes = is_string($notes) ? $notes : null;

                try {
                    $service = app(BonusApprovalService::class);
                    $name === 'approve' ? $service->approve($record, $notes) : $service->reject($record, $notes);
                } catch (InvalidStatusTransition $invalidStatusTransition) {
                    Notification::make()->danger()->title('Unable to record the decision')->body($invalidStatusTransition->getMessage())->send();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSuggestion(array $data): BonusSuggestion
    {
        return BonusSuggestion::query()->create([
            ...$data,
            'sales_plan_id' => $this->calculation()->sales_plan_id,
            'employee_id' => $this->calculation()->employee_id,
            'status' => BonusSuggestionStatus::Pending,
        ]);
    }

    private function calculation(): EmployeeSalaryCalculation
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof EmployeeSalaryCalculation) {
            throw new LogicException('Expected the owner record of BonusSuggestionsRelationManager to be an EmployeeSalaryCalculation.');
        }

        return $record;
    }
}
