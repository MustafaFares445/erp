<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the admin user's chosen interface language (English by default),
 * stored on the user record and mirrored in the session for guests.
 * Numbers are always formatted with Western digits, whatever the language.
 */
final class SetAdminLocale
{
    public const string SESSION_KEY = 'admin_locale';

    public const string DEFAULT_LOCALE = 'en';

    /** @var list<string> */
    public const array SUPPORTED_LOCALES = ['ar', 'en'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user instanceof User
            ? $user->locale
            : ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null);

        App::setLocale(
            is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE,
        );
        Number::useLocale('en');

        return $next($request);
    }
}
