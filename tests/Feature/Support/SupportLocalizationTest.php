<?php

declare(strict_types=1);

use App\Enums\CustomerSupportStage;
use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\MaintenanceKind;
use App\Enums\ServiceAppointmentStatus;
use App\Enums\SlaMilestoneKey;
use App\Enums\SupportAssignmentStrategy;
use App\Enums\SupportAutomationAction;
use App\Enums\SupportAutomationEvent;
use App\Enums\SupportAutomationRunStatus;
use App\Enums\SupportEntitlementStatus;
use App\Enums\TicketAssignmentSource;
use App\Enums\TicketBlocker;
use App\Enums\TicketKnowledgeLinkType;
use App\Enums\TicketStage;
use App\Enums\WarrantyFailureCategory;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use App\Filament\Resources\ServiceAppointments\ServiceAppointmentResource;
use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use App\Filament\Resources\SupportEquipment\SupportEquipmentResource;
use App\Filament\Resources\SupportQueues\SupportQueueResource;
use App\Filament\Resources\SupportRoutingRules\SupportRoutingRuleResource;
use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use App\Filament\Resources\SupportSkills\SupportSkillResource;
use App\Filament\Resources\SupportTeams\SupportTeamResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

/**
 * Support & Maintenance service-management UI must ship in English and Arabic.
 *
 * English text is the JSON translation key (`__('English text')`), so only
 * `lang/ar.json` needs an entry. Structured keys (`admin.resources.*`,
 * `dashboards.*`, ...) live in the PHP lang files of both locales.
 */

/**
 * @return list<string>
 */
function supportLocalizationFiles(): array
{
    $directories = [
        app_path('Filament/Resources/SupportTeams'),
        app_path('Filament/Resources/SupportSkills'),
        app_path('Filament/Resources/SupportQueues'),
        app_path('Filament/Resources/SupportRoutingRules'),
        app_path('Filament/Resources/SupportAutomationRules'),
        app_path('Filament/Resources/ServiceAppointments'),
        app_path('Filament/Resources/SupportEquipment'),
        app_path('Filament/Resources/KnowledgeArticles'),
        app_path('Filament/Resources/KnowledgeArticleCategories'),
        app_path('Filament/Resources/SlaPolicies'),
        app_path('Filament/Resources/SlaCalendars'),
        app_path('Filament/Resources/SupportServiceLevels'),
        app_path('Filament/Resources/SupportEntitlements'),
        app_path('Filament/Resources/SupportReports'),
        app_path('Filament/Resources/Tickets'),
        app_path('Filament/Resources/MaintenanceRequests'),
        app_path('Filament/Resources/ServiceRecords'),
        app_path('Services/Support'),
        resource_path('views/filament/resources/tickets'),
        resource_path('views/filament/resources/support-equipment'),
        resource_path('views/filament/support-reports'),
    ];

    $files = [
        app_path('Filament/Pages/SupportDashboard.php'),
        ...(glob(app_path('Filament/Widgets/Support*.php')) ?: []),
        ...(glob(app_path('Enums/{CustomerSupportStage,KnowledgeArticleStatus,KnowledgeArticleVisibility,MaintenanceKind,ServiceAppointmentStatus,SlaMilestoneKey,Support*,Ticket*}.php'), GLOB_BRACE) ?: []),
    ];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach (File::allFiles($directory) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * Every literal `__('...')` / `__("...")` string used by the Support UI files,
 * mapped to the first file that uses it.
 *
 * @return array<string, string>
 */
function supportLocalizationStrings(): array
{
    $strings = [];

    foreach (supportLocalizationFiles() as $path) {
        $contents = (string) file_get_contents($path);

        preg_match_all('/(?<![\w>])__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $string = stripslashes(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
            if ($string === '') {
                continue;
            }
            if (str_contains($string, '$')) {
                continue;
            }

            $strings[$string] ??= str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    return $strings;
}

/**
 * @return list<string>
 */
function supportLocalizationPlaceholders(string $text): array
{
    preg_match_all('/:[A-Za-z_]+/', $text, $matches);

    $placeholders = array_values(array_unique($matches[0]));
    sort($placeholders);

    return $placeholders;
}

/**
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function supportRegistryLabelKeys(array $node): array
{
    $keys = [];

    foreach ($node as $key => $value) {
        if ($key === 'label' && is_string($value) && str_starts_with($value, 'admin.')) {
            $keys[] = $value;
        } elseif (is_array($value)) {
            array_push($keys, ...supportRegistryLabelKeys($value));
        }
    }

    return array_values(array_unique($keys));
}

it('scans a meaningful set of support files', function (): void {
    expect(count(supportLocalizationFiles()))->toBeGreaterThan(100)
        ->and(count(supportLocalizationStrings()))->toBeGreaterThan(200);
});

it('translates every literal support string into Arabic', function (): void {
    /** @var array<string, string> $arabic */
    $arabic = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = [];
    $placeholderMismatch = [];

    foreach (supportLocalizationStrings() as $string => $file) {
        $string = (string) $string;

        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*\.$/i', $string) === 1) {
            // Key prefix completed at runtime (e.g. `admin.support.sla_state.` + value).
            continue;
        }

        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/i', $string) === 1) {
            // Structured key: must resolve to something other than the key itself in both locales.
            foreach (['en', 'ar'] as $locale) {
                if (__($string, [], $locale) === $string) {
                    $missing[] = "{$locale}: {$string} ({$file})";
                }
            }

            continue;
        }

        if (! isset($arabic[$string]) || mb_trim($arabic[$string]) === '') {
            $missing[] = "ar.json: {$string} ({$file})";

            continue;
        }

        if (supportLocalizationPlaceholders($string) !== supportLocalizationPlaceholders($arabic[$string])) {
            $placeholderMismatch[] = "{$string} ({$file})";
        }
    }

    expect($missing)->toBe([])
        ->and($placeholderMismatch)->toBe([]);
});

it('keeps lang/ar.json valid UTF-8 JSON without a BOM or CRLF line endings', function (): void {
    $contents = (string) file_get_contents(lang_path('ar.json'));

    expect(str_starts_with($contents, "\xEF\xBB\xBF"))->toBeFalse()
        ->and(json_decode($contents, true, flags: JSON_THROW_ON_ERROR))->toBeArray()
        ->and(str_contains($contents, "\r"))->toBeFalse();
});

it('resolves every support navigation label in both locales', function (): void {
    $support = collect(AdminModuleRegistry::groups())->firstWhere('key', 'support');

    expect($support)->toBeArray();

    $keys = supportRegistryLabelKeys($support);

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        $english = __($key, [], 'en');
        $arabic = __($key, [], 'ar');

        expect($english)->not->toBe($key, "Missing English translation for {$key}")
            ->and($arabic)->not->toBe($key, "Missing Arabic translation for {$key}")
            ->and($arabic)->not->toBe($english, "Arabic label for {$key} is identical to English");
    }
});

it('localizes the support resource navigation and model labels', function (string $resource): void {
    $original = App::getLocale();

    try {
        $labels = [];

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);

            $labels[$locale] = [
                $resource::getNavigationLabel(),
                $resource::getModelLabel(),
                $resource::getPluralModelLabel(),
            ];

            foreach ($labels[$locale] as $label) {
                expect($label)->not->toStartWith('admin.', "{$resource} returns an untranslated key in {$locale}");
            }
        }

        expect($labels['ar'])->not->toBe($labels['en']);
    } finally {
        App::setLocale($original);
    }
})->with([
    SupportTeamResource::class,
    SupportSkillResource::class,
    SupportQueueResource::class,
    SupportRoutingRuleResource::class,
    SupportAutomationRuleResource::class,
    ServiceAppointmentResource::class,
    SupportEquipmentResource::class,
    KnowledgeArticleResource::class,
    KnowledgeArticleCategoryResource::class,
    SlaCalendarResource::class,
    SupportServiceLevelResource::class,
    SupportEntitlementResource::class,
]);

it('gives every new support enum case a distinct Arabic label', function (string $enum): void {
    $original = App::getLocale();

    try {
        foreach ($enum::cases() as $case) {
            App::setLocale('en');
            $english = $case->label();

            App::setLocale('ar');
            $arabic = $case->label();

            expect($arabic)->not->toBe($english, "{$enum}::{$case->name} has no Arabic label")
                ->and($arabic)->not->toBeEmpty()
                ->and(preg_match('/\p{Arabic}/u', $arabic))->toBe(1, "{$enum}::{$case->name} Arabic label has no Arabic text");
        }
    } finally {
        App::setLocale($original);
    }
})->with([
    CustomerSupportStage::class,
    KnowledgeArticleStatus::class,
    KnowledgeArticleVisibility::class,
    MaintenanceKind::class,
    ServiceAppointmentStatus::class,
    SlaMilestoneKey::class,
    SupportAssignmentStrategy::class,
    SupportAutomationAction::class,
    SupportAutomationEvent::class,
    SupportAutomationRunStatus::class,
    SupportEntitlementStatus::class,
    TicketAssignmentSource::class,
    TicketBlocker::class,
    TicketKnowledgeLinkType::class,
    TicketStage::class,
]);

it('ships english and arabic copy for every support notification event', function (): void {
    foreach (['ticket_updated', 'ticket_feedback_requested', 'sla_at_risk', 'maintenance_billed', 'maintenance_due'] as $event) {
        foreach (['en', 'ar'] as $locale) {
            expect(__("notification_templates.events.{$event}.name", [], $locale))
                ->not->toBe("notification_templates.events.{$event}.name")
                ->and(__("notification_templates.events.{$event}.description", [], $locale))
                ->not->toBe("notification_templates.events.{$event}.description");
        }
    }
});

it('ships arabic copy for the ticket lifecycle actions and the equipment entitlement labels', function (): void {
    foreach ([
        'Wait for Customer', 'Reopen Ticket', 'Resolve Ticket', 'Cancel Ticket', 'Start Work', 'Resume Work', 'Close Ticket',
        'Support entitlement', 'No active support entitlement',
    ] as $label) {
        expect(__($label, [], 'ar'))->not->toBe($label);
    }
});

it('translates warranty failure categories instead of title-casing the raw value', function (): void {
    App::setLocale('ar');

    foreach (WarrantyFailureCategory::cases() as $category) {
        expect($category->label())->not->toBe(str($category->value)->replace('_', ' ')->title()->toString());
    }
});
