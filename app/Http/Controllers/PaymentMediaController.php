<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\Payment;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentMediaController
{
    use StreamsModelMedia;

    public function preview(Payment $payment, Media $media): StreamedResponse
    {
        $this->authorizeMedia($payment, $media, ['payment-proof']);

        return $this->stream($media, 'inline');
    }

    public function download(Payment $payment, Media $media): StreamedResponse
    {
        $this->authorizeMedia($payment, $media, ['payment-proof']);

        return $this->stream($media, 'attachment');
    }
}
