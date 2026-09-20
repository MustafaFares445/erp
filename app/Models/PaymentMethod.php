<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'type', 'chart_account_id', 'is_active', 'requires_proof'])]
/** @property int $id */
final class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    protected $attributes = [
        'type' => 'bank_transfer',
        'is_active' => true,
        'requires_proof' => false,
    ];

    /** @return BelongsTo<ChartAccount, $this> */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function isStripe(): bool
    {
        return $this->type === PaymentMethodType::Stripe;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'is_active' => 'boolean',
            'requires_proof' => 'boolean',
        ];
    }
}
