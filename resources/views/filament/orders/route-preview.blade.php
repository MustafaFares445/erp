<div class="space-y-6">
    @php($mappableRoutes = collect($routes)->filter(fn (array $route): bool => $route['map_x'] !== null && $route['map_y'] !== null))

    @if($mappableRoutes->isNotEmpty())
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-col gap-3 border-b border-gray-100 bg-gradient-to-r from-primary-50 to-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:from-primary-500/10 dark:to-gray-900">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary-700 dark:text-primary-300">Delivery plan</p>
                    <h3 class="mt-1 text-base font-semibold text-gray-950 dark:text-white">Warehouse routes to {{ $customer->company_name }}</h3>
                </div>
                <div class="inline-flex items-center gap-2 self-start rounded-full bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 sm:self-auto dark:bg-white/10 dark:text-gray-200 dark:ring-white/10">
                    <span class="inline-block size-2.5 rounded-full bg-primary-600"></span>
                    Customer destination
                </div>
            </div>

            <div class="p-4 sm:p-5">
                <svg viewBox="0 0 100 100" class="h-80 w-full rounded-xl bg-slate-50 ring-1 ring-inset ring-slate-200 dark:bg-white/5 dark:ring-white/10" role="img" aria-label="Delivery route preview">
                    <defs>
                        <pattern id="route-grid" width="10" height="10" patternUnits="userSpaceOnUse">
                            <path d="M 10 0 L 0 0 0 10" fill="none" stroke="currentColor" stroke-opacity="0.08" stroke-width="0.35" />
                        </pattern>
                    </defs>
                    <rect width="100" height="100" fill="url(#route-grid)" class="text-slate-500 dark:text-white" />

                    @foreach($mappableRoutes as $route)
                        <line
                            x1="50"
                            y1="50"
                            x2="{{ $route['map_x'] }}"
                            y2="{{ $route['map_y'] }}"
                            stroke="{{ $route['color'] }}"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-dasharray="3 1.5"
                        />
                    @endforeach

                    <circle cx="50" cy="50" r="4.8" fill="#dbeafe" />
                    <circle cx="50" cy="50" r="2.8" fill="#2563eb" />
                    <text x="50" y="58.5" text-anchor="middle" class="fill-gray-700 text-[4px] font-semibold dark:fill-gray-200">Customer</text>

                    @foreach($mappableRoutes as $route)
                        <circle cx="{{ $route['map_x'] }}" cy="{{ $route['map_y'] }}" r="4.5" fill="white" opacity="0.92" />
                        <circle cx="{{ $route['map_x'] }}" cy="{{ $route['map_y'] }}" r="2.8" fill="{{ $route['color'] }}" />
                        <text x="{{ $route['map_x'] }}" y="{{ $route['map_y'] + 7 }}" text-anchor="middle" class="fill-gray-700 text-[3.5px] font-medium dark:fill-gray-200">
                            {{ $route['warehouse_name'] }}
                        </text>
                    @endforeach
                </svg>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Each color represents a separate shipment. Route lines are location-based estimates; final driving routes may vary.
                </p>
            </div>
        </section>
    @else
        <div class="rounded-2xl border border-warning-300 bg-warning-50 p-5 text-sm text-warning-800 shadow-sm dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">
            <p class="font-semibold">Map preview is not ready yet</p>
            <p class="mt-1">Add map coordinates to the customer and each selected warehouse to display delivery routes and estimates.</p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach($routes as $route)
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3 px-5 pt-5">
                        <span class="mt-1 inline-block size-3 shrink-0 rounded-full ring-4 ring-gray-100 dark:ring-white/10" style="background-color: {{ $route['color'] }}"></span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">Shipment source</p>
                            <h3 class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $route['warehouse_name'] }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $route['warehouse_address'] ?? 'Warehouse address not recorded' }}</p>
                        </div>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-px border-y border-gray-100 bg-gray-100 text-sm dark:border-white/10 dark:bg-white/10">
                    <div class="bg-white px-5 py-3 dark:bg-gray-900">
                        <dt class="text-gray-500 dark:text-gray-400">Destination</dt>
                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $customer->company_name }}</dd>
                    </div>
                    <div class="bg-white px-5 py-3 dark:bg-gray-900">
                        <dt class="text-gray-500 dark:text-gray-400">Distance</dt>
                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">
                            {{ $route['distance_km'] === null ? 'Unavailable' : number_format($route['distance_km'], 1).' km' }}
                        </dd>
                    </div>
                    <div class="bg-white px-5 py-3 dark:bg-gray-900">
                        <dt class="text-gray-500 dark:text-gray-400">Estimated time</dt>
                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">
                            {{ $route['estimated_minutes'] === null ? 'Unavailable' : 'About '.$route['estimated_minutes'].' min' }}
                        </dd>
                    </div>
                    <div class="bg-white px-5 py-3 dark:bg-gray-900">
                        <dt class="text-gray-500 dark:text-gray-400">Total items</dt>
                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ number_format(collect($route['products'])->sum('quantity'), 3) }}</dd>
                    </div>
                </dl>

                <ul class="space-y-2 px-5 py-4 text-sm">
                    @foreach($route['products'] as $product)
                        <li class="flex justify-between gap-3 text-gray-700 dark:text-gray-200">
                            <span>{{ $product['name'] }}</span>
                            <span class="font-medium">{{ number_format($product['quantity'], 3) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
