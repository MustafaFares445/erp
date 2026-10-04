<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportQueues\Schemas;

use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\SupportTeam;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupportQueueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Queue'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('support_team_id')
                        ->label(__('Team'))
                        ->options(fn (): array => SupportTeam::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                    TextInput::make('sort_order')->numeric()->minValue(0)->default(100)->required(),
                    Toggle::make('is_active')->default(true),
                    Select::make('criteria.statuses')
                        ->label(__('Statuses'))
                        ->options(collect(TicketStatus::cases())->mapWithKeys(static fn (TicketStatus $status): array => [$status->value => $status->label()])->all())
                        ->multiple(),
                    Select::make('criteria.priorities')
                        ->label(__('Priorities'))
                        ->options(collect(TicketPriority::cases())->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => $priority->label()])->all())
                        ->multiple(),
                    Select::make('criteria.service_paths')
                        ->label(__('Service paths'))
                        ->options(collect(TicketServicePath::cases())->mapWithKeys(static fn (TicketServicePath $path): array => [$path->value => $path->label()])->all())
                        ->multiple(),
                    Toggle::make('criteria.unassigned')->label(__('Only unassigned')),
                    Toggle::make('criteria.sla_risk')->label(__('Only SLA risk')),
                    TextInput::make('criteria.waiting_customer_hours')
                        ->label(__('Waiting customer longer than (hours)'))
                        ->numeric()->minValue(1)->nullable(),
                    TextInput::make('criteria.older_than_hours')
                        ->label(__('Ticket older than (hours)'))
                        ->numeric()->minValue(1)->nullable(),
                ])
                ->columns(2),
        ]);
    }
}
