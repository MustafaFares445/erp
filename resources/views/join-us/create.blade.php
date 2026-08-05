<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join Us</title>
    @vite(['resources/css/app.css', 'resources/js/join-us.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-950 antialiased">
    <header class="relative overflow-hidden bg-slate-950 text-white">
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/20 via-transparent to-cyan-400/10" aria-hidden="true"></div>
        <div class="absolute -right-24 -top-32 h-80 w-80 rounded-full bg-indigo-500/20 blur-3xl" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-6xl px-4 pb-16 pt-10 sm:px-6 sm:pb-20 sm:pt-14 lg:px-8">
            <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold tracking-wide text-indigo-100">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                Customer application
            </div>
            <h1 class="mt-5 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">Join us as a customer</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">
                Tell us about your business and preferred delivery location. Our team will review your application
                before activating your account.
            </p>
        </div>
    </header>

    <main class="relative mx-auto -mt-8 max-w-6xl px-4 pb-16 sm:px-6 lg:px-8">
        @if ($errors->any())
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-900 shadow-sm" role="alert">
                <div class="flex gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.885c.673 1.166-.17 2.624-1.515 2.624H3.72c-1.345 0-2.188-1.458-1.515-2.624l6.28-10.885ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="font-semibold">Please review the highlighted information.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-red-800">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('join-us.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="join-section" aria-labelledby="account-heading">
                    <div class="join-section-heading">
                        <span class="join-section-number">1</span>
                        <div>
                            <h2 id="account-heading" class="text-lg font-semibold text-slate-950">Account details</h2>
                            <p class="mt-1 text-sm text-slate-500">Your sign-in and primary contact information.</p>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="join-label">
                            <span>Full name <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Username <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="text" name="username" value="{{ old('username') }}" autocomplete="username" required class="join-input">
                        </label>
                        <label class="join-label sm:col-span-2">
                            <span>Account email <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required class="join-input">
                            <span class="mt-1.5 block text-xs font-normal text-slate-500">You will use this email to sign in.</span>
                        </label>
                        <label class="join-label">
                            <span>Password <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="password" name="password" autocomplete="new-password" required class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Confirm password <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="password" name="password_confirmation" autocomplete="new-password" required class="join-input">
                        </label>
                    </div>
                </section>

                <section class="join-section" aria-labelledby="company-heading">
                    <div class="join-section-heading">
                        <span class="join-section-number">2</span>
                        <div>
                            <h2 id="company-heading" class="text-lg font-semibold text-slate-950">Company information</h2>
                            <p class="mt-1 text-sm text-slate-500">The business this customer account represents.</p>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="join-label sm:col-span-2">
                            <span>Company name <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="text" name="company_name" value="{{ old('company_name') }}" autocomplete="organization" required class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Company email <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="email" name="company_email" value="{{ old('company_email') }}" required class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Company phone <span class="text-red-500" aria-hidden="true">*</span></span>
                            <input type="tel" name="company_phone" value="{{ old('company_phone') }}" autocomplete="tel" required class="join-input">
                        </label>
                    </div>
                </section>
            </div>

            <section class="join-section" aria-labelledby="delivery-heading">
                <div class="join-section-heading">
                    <span class="join-section-number">3</span>
                    <div>
                        <h2 id="delivery-heading" class="text-lg font-semibold text-slate-950">Delivery location</h2>
                        <p class="mt-1 text-sm text-slate-500">Search for an address or click the map to place the delivery pin.</p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <label for="map-search" class="sr-only">Search delivery address</label>
                    <div class="relative flex-1">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.031 4.86l3.554 3.555a.75.75 0 1 1-1.06 1.06l-3.555-3.554A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
                        </svg>
                        <input type="search" id="map-search" placeholder="Street, neighborhood, or city&hellip;" class="join-input mt-0 pl-11">
                    </div>
                    <button type="button" id="map-search-button" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.031 4.86l3.554 3.555a.75.75 0 1 1-1.06 1.06l-3.555-3.554A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
                        </svg>
                        Search
                    </button>
                </div>

                <div id="join-us-map" data-default-zoom="15" class="mt-4 h-80 w-full overflow-hidden rounded-2xl border-4 border-white shadow-md ring-1 ring-slate-200 sm:h-[28rem]"></div>
                <p id="location-selection-status" class="mt-4 flex items-start gap-2 rounded-xl bg-indigo-50 px-4 py-3 text-sm text-indigo-900" role="status" aria-live="polite">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9.69 18.933 9.5 19l-.19-.067a9.99 9.99 0 0 1-4.157-2.61C3.286 14.486 2 12.018 2 9.5a7.5 7.5 0 1 1 15 0c0 2.518-1.286 4.986-3.153 6.823a9.99 9.99 0 0 1-4.157 2.61ZM10 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" clip-rule="evenodd" />
                    </svg>
                    <span>Click the map or search for your delivery location to place a pin.</span>
                </p>

                <input type="hidden" id="latitude" name="latitude" value="{{ old('latitude') }}" required>
                <input type="hidden" id="longitude" name="longitude" value="{{ old('longitude') }}" required>

                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="join-label">
                        <span>Country <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="text" id="country" name="country" value="{{ old('country') }}" autocomplete="country-name" required class="join-input">
                    </label>
                    <label class="join-label">
                        <span>City <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="text" id="city" name="city" value="{{ old('city') }}" autocomplete="address-level2" required class="join-input">
                    </label>
                    <label class="join-label sm:col-span-2">
                        <span>Address details</span>
                        <textarea id="address" name="address" rows="3" autocomplete="street-address" class="join-input resize-y">{{ old('address') }}</textarea>
                        <span class="mt-1.5 block text-xs font-normal text-slate-500">Pre-filled from the map. You can edit it before submitting.</span>
                    </label>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="join-section" aria-labelledby="accountant-heading">
                    <div class="join-section-heading">
                        <span class="join-section-number">4</span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="accountant-heading" class="text-lg font-semibold text-slate-950">Accountant</h2>
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Optional</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">Helps us reach the right person about invoicing.</p>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="join-label sm:col-span-2">
                            <span>Accountant's name</span>
                            <input type="text" name="accountant_name" value="{{ old('accountant_name') }}" class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Accountant's phone</span>
                            <input type="tel" name="accountant_phone" value="{{ old('accountant_phone') }}" class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Accountant's email</span>
                            <input type="email" name="accountant_email" value="{{ old('accountant_email') }}" class="join-input">
                        </label>
                    </div>
                </section>

                <section class="join-section" aria-labelledby="contact-heading">
                    <div class="join-section-heading">
                        <span class="join-section-number">5</span>
                        <div>
                            <h2 id="contact-heading" class="text-lg font-semibold text-slate-950">Contact person</h2>
                            <p class="mt-1 text-sm text-slate-500">Choose who we should contact about this account.</p>
                        </div>
                    </div>

                    <fieldset class="mt-6 space-y-3">
                        <legend class="sr-only">Select the contact person</legend>
                        <label class="join-radio-option">
                            <input type="radio" name="contact_is_self" value="1" class="h-4 w-4 accent-indigo-600" {{ old('contact_is_self', '1') === '1' ? 'checked' : '' }}>
                            <span>Contact me using the account details above</span>
                        </label>
                        <label class="join-radio-option">
                            <input type="radio" name="contact_is_self" value="0" class="h-4 w-4 accent-indigo-600" {{ old('contact_is_self') === '0' ? 'checked' : '' }}>
                            <span>Contact someone else instead</span>
                        </label>
                    </fieldset>

                    <div id="contact-person-fields" class="mt-5 hidden grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="join-label sm:col-span-2">
                            <span>Name</span>
                            <input type="text" name="contact_name" value="{{ old('contact_name') }}" class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Phone</span>
                            <input type="tel" name="contact_phone" value="{{ old('contact_phone') }}" class="join-input">
                        </label>
                        <label class="join-label">
                            <span>Email</span>
                            <input type="email" name="contact_email" value="{{ old('contact_email') }}" class="join-input">
                        </label>
                    </div>
                </section>
            </div>

            <section class="join-section" aria-labelledby="documents-heading">
                <div class="join-section-heading">
                    <span class="join-section-number">6</span>
                    <div>
                        <h2 id="documents-heading" class="text-lg font-semibold text-slate-950">Required documents</h2>
                        <p class="mt-1 text-sm text-slate-500">PDF, JPG, PNG, or WebP. Maximum file size is 5 MB each.</p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="join-file-field">
                        <span class="join-label">License <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="file" name="license" required class="join-file-input">
                    </label>
                    <label class="join-file-field">
                        <span class="join-label">Tax certificate <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="file" name="tax_certificate" required class="join-file-input">
                    </label>
                    <label class="join-file-field">
                        <span class="join-label">Passport <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="file" name="passport" accept="image/*" required class="join-file-input">
                    </label>
                    <label class="join-file-field">
                        <span class="join-label">Personal identity <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="file" name="personal_identity" accept="image/*" required class="join-file-input">
                    </label>
                    <label class="join-file-field">
                        <span class="join-label">Accommodation <span class="text-red-500" aria-hidden="true">*</span></span>
                        <input type="file" name="accommodation" accept="image/*" required class="join-file-input">
                    </label>
                </div>
            </section>

            <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-6">
                <div>
                    <p class="text-sm font-semibold text-slate-900">Ready to submit?</p>
                    <p class="mt-1 text-sm text-slate-500">We will review your information before activating the account.</p>
                </div>
                <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 sm:w-auto">
                    Submit application
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M3.25 10a.75.75 0 0 1 .75-.75h10.19l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H4a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </form>
    </main>
</body>
</html>
