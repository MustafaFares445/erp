<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRoutingRules\Schemas;

use App\Enums\TicketServicePath;
use App\Enums\TicketType;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SupportSkill;
use App\Models\SupportTeam;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupportRoutingRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Routing rule'))
                ->description(__('More specific rules should use a lower precedence number. Empty match fields behave as wildcards.'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('precedence')->numeric()->minValue(1)->default(100)->required(),
                    Toggle::make('is_active')->default(true),
                    Toggle::make('auto_assign')->label(__('Auto assign a qualified agent'))->default(false),
                    Select::make('ticket_type')
                        ->options(collect(TicketType::cases())->mapWithKeys(static fn (TicketType $type): array => [$type->value => $type->label()])->all())
                        ->nullable(),
                    Select::make('service_path')
                        ->options(collect(TicketServicePath::cases())->mapWithKeys(static fn (TicketServicePath $path): array => [$path->value => $path->label()])->all())
                        ->nullable(),
                    Select::make('product_variant_id')
                        ->label(__('Product variant'))
                        ->options(fn (): array => ProductVariant::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                    Select::make('product_category_id')
                        ->label(__('Product category'))
                        ->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                    TextInput::make('customer_city')->label(__('Customer city'))->maxLength(255)->nullable(),
                    Select::make('support_team_id')
                        ->label(__('Target team'))
                        ->options(fn (): array => SupportTeam::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    Select::make('required_skill_id')
                        ->label(__('Required skill'))
                        ->options(fn (): array => SupportSkill::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->nullable(),
                ])->columns(2),
        ]);
    }
}
