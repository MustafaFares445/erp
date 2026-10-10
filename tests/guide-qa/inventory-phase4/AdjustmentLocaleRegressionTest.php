<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\InventoryReturnStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\Adjustments\Pages\EditAdjustment;
use App\Filament\Resources\Adjustments\RelationManagers\AdjustmentItemsRelationManager;
use App\Filament\Resources\InventoryCorrections\Pages\ViewInventoryCorrection;
use App\Filament\Resources\InventoryCorrections\RelationManagers\CorrectionLinesRelationManager;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\Returns\RelationManagers\ReturnLinesRelationManager;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCorrection;
use App\Models\InventoryReturn;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('renders Arabic adjustment guidance, not hardcoded English tooltip text', function (): void {
    $expected = [
        'warehouse_help' => 'اختر المستودع الذي ستُصحَّح كمياته الفعلية بهذه التسوية.',
        'number_help' => 'يُعيَّن رقم التسوية تلقائياً عند حفظ السجل.',
        'reason_help' => 'اذكر سبب التصحيح لتوثيق التغيير ومراجعته لاحقاً.',
        'serialized_unit' => 'الوحدة المرقّمة',
        'add_item' => 'إضافة بند تسوية مخزون',
    ];

    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->givePermissionTo([
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
    ]);

    app()->setLocale('ar');
    foreach ($expected as $key => $arabic) {
        expect(__('inventory_phase4.adjustment.'.$key))->toBe($arabic);
    }

    $this->actingAs($admin)->get('/locale/ar')->assertRedirect();
    $this->actingAs($admin)
        ->get('/admin/adjustments/create')
        ->assertOk()
        ->assertSee($expected['warehouse_help'])
        ->assertSee($expected['number_help'])
        ->assertSee($expected['reason_help'])
        ->assertSee($expected['add_item']);
});

it('renders localized adjustment line heading and add action on a draft edit page', function (): void {
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->givePermissionTo([
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
    ]);
    $draft = InventoryAdjustment::factory()->create();
    $this->actingAs($admin)->get('/locale/ar')->assertRedirect();
    $this->get(AdjustmentResource::getUrl('edit', ['record' => $draft]))
        ->assertOk();
    expect(AdjustmentItemsRelationManager::getTitle(
        $draft, EditAdjustment::class,
    ))->toBe(__('inventory_phase4.adjustment.items_title'));
    Livewire::actingAs($admin)
        ->test(AdjustmentItemsRelationManager::class, [
            'ownerRecord' => $draft,
            'pageClass' => EditAdjustment::class,
        ])
        ->assertSee(__('inventory_phase4.adjustment.add_item'));
});

it('shows a translated Arabic return status rather than a raw enum value', function (): void {
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->givePermissionTo(InventoryPermission::ReturnView->value);

    $posted = InventoryReturn::factory()->posted()->create();
    $this->actingAs($admin)->get('/locale/ar')->assertRedirect();
    $this->get(ReturnResource::getUrl('index'))
        ->assertOk()
        ->assertSee($posted->return_number)
        ->assertSee(InventoryReturnStatus::Posted->label());
});

it('localizes the return line relation manager heading in Arabic', function (): void {
    app()->setLocale('ar');
    $record = InventoryReturn::factory()->posted()->create();

    expect(ReturnLinesRelationManager::getTitle(
        $record,
        ViewReturn::class,
    ))->toBe('بنود المرتجع');
});

it('localizes the receipt correction relation heading in Arabic', function (): void {
    app()->setLocale('ar');
    $record = InventoryCorrection::factory()->posted()->create();

    expect(CorrectionLinesRelationManager::getTitle(
        $record,
        ViewInventoryCorrection::class,
    ))->toBe('بنود التصحيح');
});

it('translates all materialized stock conditions used by count variance worksheets', function (): void {
    app()->setLocale('ar');
    foreach ([StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged] as $condition) {
        expect($condition->label())->not->toBe($condition->value)
            ->and($condition->label())->not->toBe('');
    }
});
