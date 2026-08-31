<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupplierProductReference;
use App\Models\SupplierProductSupport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('purchasing:backfill-supplier-product-supports')]
#[Description('Creates active variant support records from active supplier product references')]
final class BackfillSupplierProductSupportsCommand extends Command
{
    public function handle(): int
    {
        $created = 0;

        SupplierProductReference::query()
            ->where('is_active', true)
            ->cursor()
            ->each(function (SupplierProductReference $reference) use (&$created): void {
                $support = SupplierProductSupport::withTrashed()->firstOrNew([
                    'supplier_id' => $reference->supplier_id,
                    'product_variant_id' => $reference->product_variant_id,
                ]);

                if (! $support->exists) {
                    $created++;
                }

                $support->forceFill(['is_active' => true, 'deleted_at' => null])->save();
            });

        $this->info("Created {$created} supplier product supports.");

        return self::SUCCESS;
    }
}
