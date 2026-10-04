<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportAutomationRules\Schemas;

use App\Enums\SupportAutomationAction;
use App\Enums\SupportAutomationEvent;
use App\Enums\TicketPriority;
use App\Models\SupportTeam;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupportAutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Rule'))
                ->description(__('Rules run in precedence order. Conditions and actions are restricted to the safe catalogue below.'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('event_key')
                        ->label(__('Event'))
                        ->options(collect(SupportAutomationEvent::cases())
                            ->mapWithKeys(static fn (SupportAutomationEvent $event): array => [$event->value => $event->label()])
                            ->all())
                        ->required(),
                    TextInput::make('precedence')->numeric()->minValue(0)->default(100)->required(),
                    Toggle::make('is_active')->default(true),
                    Toggle::make('stop_processing')
                        ->label(__('Stop after this matching rule'))
                        ->helperText(__('When enabled, lower-priority rules for the same event are skipped after this rule succeeds.')),
                ])
                ->columns(2),

            Section::make(__('Conditions'))
                ->description(__('All conditions must match. Empty conditions mean the rule matches every occurrence of the selected event.'))
                ->schema([
                    Repeater::make('conditions')
                        ->hiddenLabel()
                        ->default([])
                        ->schema([
                            Select::make('field')
                                ->label(__('Condition field'))
                                ->options([
                                    'type' => __('Ticket type'),
                                    'priority' => __('Priority'),
                                    'status' => __('Status'),
                                    'customer_impact' => __('Customer impact'),
                                    'service_path' => __('Service path'),
                                    'support_team_id' => __('Support team ID'),
                                    'customer_id' => __('Customer ID'),
                                    'product_variant_id' => __('Product variant ID'),
                                    'warranty_status' => __('Warranty status'),
                                    'age_hours' => __('Ticket age in hours'),
                                    'waiting_customer_hours' => __('Waiting customer hours'),
                                    'sla_state' => __('SLA state'),
                                ])
                                ->required(),
                            Select::make('operator')
                                ->label(__('Comparison operator'))
                                ->options([
                                    'equals' => '=',
                                    'not_equals' => '!=',
                                    'in' => __('In list'),
                                    'not_in' => __('Not in list'),
                                    'gte' => '>=',
                                    'lte' => '<=',
                                    'is_null' => __('Is empty'),
                                    'not_null' => __('Is not empty'),
                                ])
                                ->default('equals')
                                ->required(),
                            TextInput::make('value')
                                ->helperText(__('For "in" operators, provide a JSON array such as ["urgent","high"].')),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),

            Section::make(__('Actions'))
                ->description(__('Actions are executed in order. No raw PHP, SQL, shell commands, or arbitrary webhooks are allowed.'))
                ->schema([
                    Repeater::make('actions')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->schema([
                            Select::make('type')
                                ->label(__('Action type'))
                                ->options(collect(SupportAutomationAction::cases())
                                    ->mapWithKeys(static fn (SupportAutomationAction $action): array => [$action->value => $action->label()])
                                    ->all())
                                ->required(),
                            Select::make('team_id')
                                ->label(__('Team'))
                                ->options(fn (): array => SupportTeam::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable(),
                            Select::make('priority')
                                ->options(collect(TicketPriority::cases())
                                    ->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => $priority->label()])
                                    ->all()),
                            Select::make('user_id')
                                ->label(__('User / note author / follower'))
                                ->options(fn (): array => User::query()->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                                ->searchable(),
                            Textarea::make('message')->rows(2)->label(__('Internal note')),
                            Textarea::make('body')->rows(2)->label(__('Follow-up task body')),
                            Select::make('author_id')
                                ->label(__('Follow-up author'))
                                ->options(fn (): array => User::query()->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                                ->searchable(),
                            Select::make('assignee_id')
                                ->label(__('Follow-up assignee'))
                                ->options(fn (): array => User::query()->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                                ->searchable(),
                            TextInput::make('due_hours')->numeric()->minValue(1)->label(__('Due in hours')),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
