<?php

declare(strict_types=1);
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

it('debugs outbound resource', function (): void {
    $owner = new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };
    expect(OutboundFulfillmentResource::table(Table::make($owner)))->toBeInstanceOf(Table::class);
    expect(OutboundFulfillmentResource::infolist(Schema::make()))->toBeInstanceOf(Schema::class);
    expect(OutboundFulfillmentResource::getEloquentQuery())->not->toBeNull();
});
