<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\JoinUsRequest;
use App\Services\Crm\CustomerOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class JoinUsController
{
    private const array DocumentCollections = ['license', 'tax_certificate', 'passport', 'personal_identity', 'accommodation'];

    public function __construct(private readonly CustomerOnboardingService $onboardingService) {}

    public function create(): View
    {
        return view('join-us.create');
    }

    public function store(JoinUsRequest $request): RedirectResponse
    {
        $documents = [];

        foreach (self::DocumentCollections as $collection) {
            $documents[$collection] = $request->file($collection);
        }

        $this->onboardingService->register($request->validated(), $documents);

        return redirect()->route('join-us.thank-you');
    }

    public function show(): View
    {
        return view('join-us.thank-you');
    }
}
