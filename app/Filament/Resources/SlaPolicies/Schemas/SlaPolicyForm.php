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
                Section::make(__('SLA Targets'))
                    ->schema([
                        TextInput::make('priority')
                            ->formatStateUsing(static function (mixed $state): string {
                                /** @var TicketPriority|string $state */
                                $value = $state instanceof TicketPriority ? $state->value : $state;

                                return __(str($value)->headline()->toString());
                            })
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('response_target_minutes')
                            ->label(__('Response target (minutes)'))
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        TextInput::make('resolution_target_minutes')
                            ->label(__('Resolution target (minutes)'))
                            ->numeric()
                            ->required()
                            ->minValue(1),
                    ])
                    ->columns(3),
            ]);
    }
}
