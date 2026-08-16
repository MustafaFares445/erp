<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Ticket;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class TicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'company_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('type')
                            ->label('Type')
                            ->options(collect(TicketType::cases())
                                ->mapWithKeys(static fn (TicketType $type): array => [$type->value => str($type->value)->headline()->toString()]))
                            ->required(),
                        Select::make('priority')
                            ->label('Priority')
                            ->options(collect(TicketPriority::cases())
                                ->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => str($priority->value)->headline()->toString()]))
                            ->default(TicketPriority::Normal->value)
                            ->required(),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                        Select::make('continued_from_ticket_id')
                            ->label('Continues ticket')
                            ->relationship('continuedFromTicket', 'ticket_number')
                            ->searchable()
                            ->preload()
                            ->helperText('Link this ticket to the closed or cancelled one it continues (FR-017).')
                            ->disabledOn('edit')
                            ->columnSpanFull(),
                        Toggle::make('is_chargeable')
                            ->label('Chargeable')
                            ->live()
                            ->disabledOn('edit'),
                        TextInput::make('amount')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(static fn (Get $get): bool => (bool) $get('is_chargeable'))
                            ->visible(static fn (Get $get): bool => (bool) $get('is_chargeable'))
                            ->disabledOn('edit'),
                        Select::make('currency')
                            ->options([
                                'USD' => 'US Dollar (USD)',
                                'AED' => 'UAE Dirham (AED)',
                            ])
                            ->native(false)
                            ->required(static fn (Get $get): bool => (bool) $get('is_chargeable'))
                            ->visible(static fn (Get $get): bool => (bool) $get('is_chargeable'))
                            ->disabledOn('edit'),
                        self::attachmentsUpload(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function attachmentsUpload(): FileUpload
    {
        return FileUpload::make('attachments')
            ->label('Attachments')
            ->disk('local')
            ->directory('ticket-attachments')
            ->visibility('private')
            ->multiple()
            ->appendFiles()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->maxSize(10240)
            ->preventFilePathTampering(
                allowFilePathUsing: static function (?Ticket $record, string $file): bool {
                    if (! $record instanceof Ticket) {
                        return false;
                    }

                    return $record->getMedia('ticket-attachments')
                        ->contains(static fn (Media $media): bool => $media->getPathRelativeToRoot() === $file);
                },
            )
            ->afterStateHydrated(static function (FileUpload $component, ?Ticket $record): void {
                if (! $record instanceof Ticket) {
                    return;
                }

                $component->state(
                    $record->getMedia('ticket-attachments')
                        ->map(static fn (Media $media): string => $media->getPathRelativeToRoot())
                        ->all(),
                );
            })
            ->columnSpanFull();
    }
}
