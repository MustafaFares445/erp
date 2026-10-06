<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Enums\TicketType;
use App\Http\Requests\Api\Customer\StoreProductQualityComplaintRequest;
use App\Http\Resources\Api\Customer\SupportTicketResource;
use App\Models\User;
use App\Services\Support\TicketIntakeService;
use Symfony\Component\HttpFoundation\Response;

final class ProductQualityComplaintController
{
    public function store(StoreProductQualityComplaintRequest $request, TicketIntakeService $service): Response
    {
        abort_unless((bool) config('support.product_quality_enabled', false), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $data = $request->validated();
        $data['type'] = TicketType::ProductQualityIssue->value;

        $ticket = $service->createForCustomer($data, $actor);
        $ticket->load([
            'productContexts.productVariant.product',
            'productContexts.inventoryLot',
            'productContexts.unit',
            'qualityResolution.customerReturnRequest',
        ]);

        return new SupportTicketResource($ticket)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
