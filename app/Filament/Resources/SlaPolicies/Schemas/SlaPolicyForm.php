<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Schemas;

use App\Enums\SlaMilestoneKey;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SlaCalendar;
use App\Models\SupportServiceLevel;
use App\Models\SupportTeam;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SlaPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('SLA rule'))
                ->description(__('Lower precedence wins. Empty match conditions act as wildcards; the resolved policy is snapshotted when a milestone starts.'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('code')->required()->maxLength(80)->unique(ignoreRecord: true),
                    Toggle::make('is_active')->default(true),
                    TextInput::make('precedence')->numeric()->minValue(1)->default(100)->required(),
                    Select::make('sla_calendar_id')
                        ->label(__('Business calendar'))
                        ->options(fn (): array => SlaCalendar::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                ])->columns(2),
            Section::make(__('Match conditions'))
                ->description(__('The rule matches only when every populated condition matches the ticket.'))
                ->schema([
                    Select::make('priority')
                        ->options(collect(TicketPriority::cases())->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => $priority->label()])->all())
                        ->nullable(),
                    Select::make('ticket_type')
                        ->options(collect(TicketType::cases())->mapWithKeys(static fn (TicketType $type): array => [$type->value => $type->label()])->all())
                        ->nullable(),
                    Select::make('service_path')
                        ->options(collect(TicketServicePath::cases())->mapWithKeys(static fn (TicketServicePath $path): array => [$path->value => $path->label()])->all())
                        ->nullable(),
                    Select::make('support_team_id')
                        ->label(__('Support team'))
                        ->options(fn (): array => SupportTeam::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                    Select::make('support_service_level_id')
                        ->label(__('Service level'))
                        ->options(fn (): array => SupportServiceLevel::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                    Select::make('customer_id')
                        ->label(__('Customer'))
                        ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->limit(500)->pluck('company_name', 'id')->all())
                        ->searchable()->nullable(),
                    Select::make('product_variant_id')
                        ->label(__('Product variant'))
                        ->options(fn (): array => ProductVariant::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                ])->columns(2),
            Section::make(__('Compatibility targets'))
                ->description(__('These targets remain the fallback for existing reports and tickets. Milestones below take precedence when configured.'))
                ->schema([
                    TextInput::make('response_target_minutes')->label(__('Response target (minutes)'))->numeric()->required()->minValue(1),
                    TextInput::make('resolution_target_minutes')->label(__('Resolution target (minutes)'))->numeric()->required()->minValue(1),
                ])->columns(2),
            Section::make(__('Milestones'))
                ->schema([
                    Repeater::make('milestones')
                        ->relationship()
                        ->schema([
                            Select::make('key')
                                ->label(__('SLA milestone'))
                                ->options(collect(SlaMilestoneKey::cases())->mapWithKeys(static fn (SlaMilestoneKey $key): array => [$key->value => $key->label()])->all())
                                ->required(),
                            TextInput::make('target_minutes')->label(__('Target (minutes)'))->numeric()->minValue(1)->required(),
                            TextInput::make('at_risk_before_minutes')->label(__('Warn before breach (minutes)'))->numeric()->minValue(0)->default(30)->required(),
                            Toggle::make('pause_when_waiting_customer')->label(__('Pause while waiting for customer'))->default(false),
                            Toggle::make('is_active')->default(true),
                            TextInput::make('sort_order')->numeric()->minValue(0)->default(10)->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->reorderable(),
                    Textarea::make('notes')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }
}
