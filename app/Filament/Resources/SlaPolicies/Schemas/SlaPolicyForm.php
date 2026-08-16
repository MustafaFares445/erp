<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Schemas;

use App\Enums\TicketPriority;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SlaPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('SLA Targets')
                    ->schema([
                        TextInput::make('priority')
                            ->formatStateUsing(static function (mixed $state): string {
                                $value = match (true) {
                                    $state instanceof TicketPriority => $state->value,
                                    is_string($state) => $state,
                                    // @codeCoverageIgnoreStart
                                    // sla_policies.priority is enum-cast and never null, so
                                    // Filament only ever passes a TicketPriority or a string here.
                                    default => '',
                                    // @codeCoverageIgnoreEnd
                                };

                                return str($value)->headline()->toString();
                            })
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('response_target_minutes')
                            ->label('Response target (minutes)')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        TextInput::make('resolution_target_minutes')
                            ->label('Resolution target (minutes)')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                    ])
                    ->columns(3),
            ]);
    }
}
