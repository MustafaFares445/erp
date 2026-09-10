<?php

declare(strict_types=1);

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

it('keeps pricing services owned by sales and rejects legacy inventory pricing references', function (): void {
    $pricingServices = [
        'PriceResolver',
        'PricingTierDiscountCalculator',
        'PricingTierService',
        'ProductPricingService',
    ];

    foreach ($pricingServices as $service) {
        expect(app_path("Services/Sales/{$service}.php"))->toBeFile()
            ->and(app_path("Services/Inventory/{$service}.php"))->not->toBeFile();
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        expect($source)->not->toBeFalse();

        foreach ($pricingServices as $service) {
            expect($source)->not->toContain("App\\Services\\Inventory\\{$service}");
        }
    }
});
