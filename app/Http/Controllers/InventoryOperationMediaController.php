<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeliveryDocument;
use App\Models\InventoryOperation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventoryOperationMediaController
{
    public function preview(InventoryOperation $operation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($operation, $media);

        return $this->stream($media, 'inline');
    }

    public function download(InventoryOperation $operation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($operation, $media);

        return $this->stream($media, 'attachment');
    }

    private function authorizeMedia(InventoryOperation $operation, Media $media): void
    {
        abort_unless(
            $media->model_type === $operation->getMorphClass()
                && $media->model_id === $operation->getKey()
                && in_array($media->collection_name, array_map(
                    static fn (DeliveryDocument $document): string => $document->value,
                    DeliveryDocument::cases(),
                ), true),
            Response::HTTP_NOT_FOUND,
        );

        Gate::authorize('view', $operation);
    }

    private function stream(Media $media, string $disposition): StreamedResponse
    {
        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [],
            $disposition,
        );
    }
}
