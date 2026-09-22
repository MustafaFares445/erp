<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\TicketCustomerImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Ticket;
use App\Services\Support\TicketPriorityResolver;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class TicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket')
                    ->description('Capture the customer issue first. Equipment, warranty and payment are decided during triage.')
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
                            ->live()
                            ->afterStateUpdated(static fn (Set $set, Get $get): mixed => $set('priority', self::proposedPriority($get)->value))
                            ->required(),
                        Select::make('customer_impact')
                            ->label('Customer-reported impact')
                            ->helperText('What the customer told us — kept separate from the priority support decides below.')
                            ->options(collect(TicketCustomerImpact::cases())
                                ->mapWithKeys(static fn (TicketCustomerImpact $impact): array => [$impact->value => $impact->label()]))
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(static fn (Set $set, Get $get): mixed => $set('priority', self::proposedPriority($get)->value)),
                        Select::make('priority')
                            ->label('Priority')
                            ->helperText('Proposed from type and customer impact — support can override.')
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
                            ->helperText('Link this ticket to the closed or cancelled ticket it continues.')
                            ->disabledOn('edit')
                            ->columnSpanFull(),
                        self::attachmentsUpload(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function proposedPriority(Get $get): TicketPriority
    {
        $typeValue = $get('type');
        $type = is_string($typeValue) ? TicketType::tryFrom($typeValue) : null;

        if ($type === null) {
            return TicketPriority::Normal;
        }

        $impactValue = $get('customer_impact');
        $impact = is_string($impactValue) ? TicketCustomerImpact::tryFrom($impactValue) : null;

        return app(TicketPriorityResolver::class)->resolve($type, $impact);
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
