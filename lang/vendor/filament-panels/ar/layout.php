<?php

declare(strict_types=1);

// Overrides only the `direction` key of Filament's own `filament-panels::layout`
// translations. Every other key in that file falls back to English via
// `fallback_locale` (config/app.php), which Laravel resolves per-key, not per-file.
return [
    'direction' => 'rtl',
];
