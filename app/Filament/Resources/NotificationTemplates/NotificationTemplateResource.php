<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Filament\Resources\NotificationTemplates\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateRenderer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Notification templates';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('key')
                ->label('Event')
                ->options(self::eventOptions())
                ->searchable()
                ->required(),
            Select::make('locale')
                ->options(['en' => 'English', 'ar' => 'Arabic'])
                ->required(),
            Select::make('channel')
                ->options(self::channelOptions())
                ->required(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
            TextInput::make('subject')
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('body')
                ->required()
                ->rows(6)
                ->columnSpanFull(),
            TagsInput::make('variables')
                ->helperText('Declare every {{ variable }} used by the subject or body.')
                ->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('Event')->searchable()->sortable(),
                TextColumn::make('locale')->badge()->sortable(),
                TextColumn::make('channel')->badge()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(self::channelOptions()),
                SelectFilter::make('locale')->options(['en' => 'English', 'ar' => 'Arabic']),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->action(function (NotificationTemplate $record): void {
                        $variables = array_fill_keys($record->variables ?? [], 'Sample');
                        $rendered = app(NotificationTemplateRenderer::class)->render(
                            NotificationEventKey::from((string) $record->key),
                            (string) $record->locale,
                            $record->channel,
                            $variables,
                        );

                        FilamentNotification::make()
                            ->title($rendered->subject ?? 'Notification preview')
                            ->body($rendered->body)
                            ->info()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'create' => CreateNotificationTemplate::route('/create'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function eventOptions(): array
    {
        $options = [];

        foreach (NotificationEventKey::cases() as $case) {
            $options[$case->value] = str($case->value)->replace('.', ' ')->headline()->toString();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function channelOptions(): array
    {
        $options = [];

        foreach (NotificationChannel::cases() as $case) {
            $options[$case->value] = str($case->value)->headline()->toString();
        }

        return $options;
    }
}
