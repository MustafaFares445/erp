<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Models\ChartAccount;
use App\Services\Accounting\Support\AccountTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('orders accounts depth-first, each parent immediately above its children', function (): void {
    $root = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $child = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'parent_id' => $root->id]);
    $grandchild = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1110', 'parent_id' => $child->id]);
    $sibling = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1200', 'parent_id' => $root->id]);

    $order = array_map(fn (ChartAccount $account): string => $account->code, (new AccountTree)->displayOrder());

    expect($order)->toBe(['1000', '1100', '1110', '1200']);
});

it('orders siblings by code as a string, so a three-character code sorts correctly', function (): void {
    ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '900']);
    ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1000']);

    $tree = new AccountTree;
    $first = array_map(fn (ChartAccount $account): string => $account->code, $tree->displayOrder());
    $second = array_map(fn (ChartAccount $account): string => $account->code, (new AccountTree)->displayOrder());

    // Textual order: '1000' sorts before '900'. A test asserting the reverse
    // would be asserting the numeric-order bug research §R5 warns against.
    expect($first)->toBe(['1000', '900'])
        ->and($first)->toBe($second);
});

it('sums a three-level subtree with rollUp()', function (): void {
    $root = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $mid = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1100', 'parent_id' => $root->id]);
    $leaf = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1110', 'parent_id' => $mid->id]);

    $ownValues = [
        $root->id => 10,
        $mid->id => 20,
        $leaf->id => 30,
    ];

    $tree = new AccountTree;

    expect($tree->rollUp($root->id, fn (int $id): int => $ownValues[$id] ?? 0))->toBe(60)
        ->and($tree->rollUp($mid->id, fn (int $id): int => $ownValues[$id] ?? 0))->toBe(50)
        ->and($tree->rollUp($leaf->id, fn (int $id): int => $ownValues[$id] ?? 0))->toBe(30);
});

it('terminates on a hierarchy cycle written directly to the database (FR-015)', function (): void {
    $parent = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $child = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1010', 'parent_id' => $parent->id]);

    // ChartOfAccountService refuses this at the write path; only a direct
    // database write can introduce a cycle.
    DB::table('chart_accounts')->where('id', $parent->id)->update(['parent_id' => $child->id]);

    $tree = new AccountTree;

    expect($tree->rollUp($parent->id, fn (int $id): int => 1))->toBe(2)
        ->and($tree->rollUp($child->id, fn (int $id): int => 1))->toBe(2)
        ->and($tree->displayOrder())->toBeArray();
});

it('counts each descendant exactly once in a rolled-up parent figure (invariant I-10)', function (): void {
    $root = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $childA = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'parent_id' => $root->id]);
    $childB = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1200', 'parent_id' => $root->id]);

    $ownValues = [$root->id => 1, $childA->id => 2, $childB->id => 4];

    $tree = new AccountTree;

    expect($tree->rollUp($root->id, fn (int $id): int => $ownValues[$id] ?? 0))->toBe(7);
});
