<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Enums\TicketCustomerImpact;
use App\Enums\TicketType;
use App\Http\Resources\Api\Customer\KnowledgeArticleResource;
use App\Models\CustomerProfile;
use App\Models\KnowledgeArticle;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\KnowledgeSuggestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class CustomerKnowledgeController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        $query = mb_trim((string) $request->query('q', ''));

        $articles = KnowledgeArticle::query()
            ->publishedForCustomers($this->locale($request))
            ->with('category')
            ->when($query !== '', static fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', '%'.$query.'%')
                    ->orWhere('summary', 'like', '%'.$query.'%');
            }))
            ->latest('published_at')
            ->paginate(20);

        return KnowledgeArticleResource::collection($articles);
    }

    public function show(Request $request, KnowledgeArticle $article): KnowledgeArticleResource
    {
        $this->ensureEnabled();

        abort_unless(
            KnowledgeArticle::query()
                ->publishedForCustomers($this->locale($request))
                ->whereKey($article->getKey())
                ->exists(),
            404,
        );

        $article->load('category');

        return new KnowledgeArticleResource($article);
    }

    public function suggestions(Request $request, KnowledgeSuggestionService $suggestions): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        $request->validate([
            'type' => ['nullable', Rule::enum(TicketType::class)],
            'customer_impact' => ['nullable', Rule::enum(TicketCustomerImpact::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'equipment_id' => ['nullable', 'integer'],
        ]);

        $ticket = new Ticket;
        $ticket->type = $request->enum('type', TicketType::class) ?? TicketType::GeneralSupport;
        $ticket->customer_impact = $request->enum('customer_impact', TicketCustomerImpact::class);
        $ticket->title = $request->string('title')->toString();
        $ticket->description = $request->string('description')->toString();

        if ($request->filled('equipment_id')) {
            $unit = $this->customer($request)
                ->ownedEquipment()
                ->whereKey($request->integer('equipment_id'))
                ->first();

            abort_unless($unit instanceof SerializedInventoryUnit, 404);

            $ticket->serializedInventoryUnit()->associate($unit);
        }

        $articles = $suggestions->suggestForTicket(
            $ticket,
            5,
            true,
            $this->locale($request),
        );

        return KnowledgeArticleResource::collection($articles);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('support.knowledge_base_enabled', false), 404);
    }

    private function locale(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User && $user->locale !== ''
            ? $user->locale
            : 'en';
    }

    private function customer(Request $request): CustomerProfile
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->customerProfile instanceof CustomerProfile, 403);

        return $user->customerProfile;
    }
}
