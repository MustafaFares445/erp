<?php

declare(strict_types=1);

namespace App\Data\Inventory;

/**
 * The next step a diagnostic finding recommends: a link to an existing screen where the
 * user performs it through the normal guarded workflow. Diagnostics never perform it.
 *
 * When the current user lacks the permission, the link is withheld and `deniedReason`
 * tells them who can act instead, so a disabled step is never silent.
 */
final readonly class InventoryDiagnosticAction
{
    public function __construct(
        public string $label,
        public ?string $url,
        public bool $allowed = true,
        public ?string $deniedReason = null,
    ) {}

    public static function link(string $label, ?string $url, bool $allowed = true): self
    {
        return new self(
            label: $label,
            url: $allowed ? $url : null,
            allowed: $allowed,
            deniedReason: $allowed ? null : __('inventory_view_ux.action_denied'),
        );
    }

    /**
     * A step that happens outside the system (physical check, call the supplier).
     */
    public static function guidance(string $label): self
    {
        return new self(label: $label, url: null);
    }
}
