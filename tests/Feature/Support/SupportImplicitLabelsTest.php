<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Filament derives a label from the field name when a component has no
 * explicit `->label(...)`. The app translates every derived label through
 * `__()` (AppServiceProvider::configureFilamentLabelTranslations), so the
 * derived English text must exist in `lang/ar.json` or the Arabic UI falls
 * back to English.
 *
 * This guard scans the Support / service-management Filament code for
 * `X::make('name')` components without an explicit label, derives the label
 * exactly as Filament does, and asserts an Arabic entry exists.
 */

/**
 * @return list<string>
 */
function implicitLabelScanFiles(): array
{
    $directories = [
        'SupportTeams', 'SupportSkills', 'SupportQueues', 'SupportRoutingRules', 'SupportAutomationRules',
        'ServiceAppointments', 'SupportEquipment', 'KnowledgeArticles', 'KnowledgeArticleCategories',
        'SlaPolicies', 'SlaCalendars', 'SupportServiceLevels', 'SupportEntitlements', 'SupportReports',
        'Tickets', 'MaintenanceRequests', 'ServiceRecords',
    ];

    $files = glob(app_path('Filament/Widgets/Support*.php')) ?: [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Filament/Resources/'.$directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Collect every `Class::make('literal')` call with the chained method names
 * and the raw argument text of each chained call.
 *
 * @return list<array{class: string, name: string, line: int, chain: list<array{method: string, args: string}>}>
 */
function implicitLabelScanComponents(string $path): array
{
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($path)),
        static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $count = count($tokens);
    $text = static fn (mixed $token): string => is_array($token) ? $token[1] : $token;

    // Consume a balanced (...) group starting just after its opening paren.
    $consume = static function (int &$i) use ($tokens, $count, $text): string {
        $depth = 1;
        $parts = [];

        while ($i < $count && $depth > 0) {
            $piece = $text($tokens[$i]);

            if ($piece === '(' || $piece === '[') {
                $depth++;
            } elseif ($piece === ')' || $piece === ']') {
                $depth--;
            }

            if ($depth > 0) {
                $parts[] = $piece;
            }

            $i++;
        }

        return implode(' ', $parts);
    };

    $components = [];

    for ($i = 0; $i < $count - 5; $i++) {
        $isClassName = is_array($tokens[$i])
            && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
        if (! $isClassName) {
            continue;
        }
        if ($text($tokens[$i + 1]) !== '::') {
            continue;
        }
        if ($text($tokens[$i + 2]) !== 'make') {
            continue;
        }
        if ($text($tokens[$i + 3]) !== '(') {
            continue;
        }

        $line = $tokens[$i][2];
        $class = (string) Str::of($tokens[$i][1])->afterLast('\\');
        $argumentStart = $i + 4;
        $cursor = $argumentStart;
        $consume($cursor);

        $isSingleStringLiteral = $cursor - $argumentStart === 2
            && is_array($tokens[$argumentStart])
            && $tokens[$argumentStart][0] === T_CONSTANT_ENCAPSED_STRING;

        $chain = [];

        while ($cursor < $count && in_array($text($tokens[$cursor]), ['->', '?->'], true)) {
            $method = $text($tokens[$cursor + 1]);
            $cursor += 2;

            if (($tokens[$cursor] ?? null) !== '(') {
                break;
            }

            $cursor++;
            $chain[] = ['method' => $method, 'args' => $consume($cursor)];
        }

        if ($isSingleStringLiteral) {
            $components[] = [
                'class' => $class,
                'name' => stripslashes(mb_substr($tokens[$argumentStart][1], 1, -1)),
                'line' => $line,
                'chain' => $chain,
            ];
        }
    }

    return $components;
}

/**
 * Mirror Filament's `getLabel()` derivation for the given component.
 *
 * Returns null for components that are not label-derived by name (sections,
 * tabs, notifications, hidden inputs, prebuilt actions, ...), have an explicit
 * label, or hide their label.
 *
 * @param  array{class: string, name: string, line: int, chain: list<array{method: string, args: string}>}  $component
 */
function implicitLabelDerive(array $component): ?string
{
    $fields = [
        'TextInput', 'Select', 'Textarea', 'Toggle', 'Checkbox', 'DatePicker', 'DateTimePicker', 'TimePicker',
        'CheckboxList', 'Radio', 'ToggleButtons', 'TagsInput', 'KeyValue', 'RichEditor', 'FileUpload', 'Repeater',
        'CurrencySelect', 'ColorPicker', 'Slider',
    ];
    $relationshipFields = ['Select', 'CheckboxList', 'Repeater'];

    $class = $component['class'];
    $name = $component['name'];

    foreach ($component['chain'] as $call) {
        $hasExplicitLabel = $call['method'] === 'label' && ! in_array(mb_trim($call['args']), ['', 'null'], true);

        if ($hasExplicitLabel || $call['method'] === 'hiddenLabel') {
            return null;
        }
    }

    $relationshipName = null;

    foreach ($component['chain'] as $call) {
        $isRelationshipCall = $call['method'] === 'relationship'
            && in_array($class, $relationshipFields, true)
            && preg_match("/^'([^']+)'/", mb_trim($call['args']), $matches) === 1;

        if ($isRelationshipCall) {
            $relationshipName = $matches[1];
        }
    }

    // Select / CheckboxList / Repeater with a relationship label from the relationship name.
    if ($relationshipName !== null) {
        $basis = Str::before($relationshipName, '.');
    } elseif (str_ends_with($class, 'Column')) {
        $basis = Str::afterLast(Str::beforeLast($name, '.'), '.');
    } elseif (str_ends_with($class, 'Entry') || in_array($class, $fields, true)) {
        $basis = Str::afterLast($name, '.');
    } elseif (str_ends_with($class, 'Filter') || str_ends_with($class, 'Constraint') || in_array($class, ['Action', 'BulkAction'], true)) {
        $basis = Str::before($name, '.');
    } else {
        return null;
    }

    return (string) str($basis)->kebab()->replace(['-', '_'], ' ')->ucfirst();
}

test('implicit labels in the support service-management UI have Arabic translations', function (): void {
    /** @var array<string, string> $arabic */
    $arabic = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);

    $untranslated = [];

    foreach (implicitLabelScanFiles() as $file) {
        foreach (implicitLabelScanComponents($file) as $component) {
            $label = implicitLabelDerive($component);

            if ($label === null) {
                continue;
            }

            $translation = $arabic[$label] ?? null;

            if (! is_string($translation) || mb_trim($translation) === '') {
                $relative = str_replace(['\\', base_path().'/'], ['/', ''], $file);
                $untranslated[] = sprintf('%s:%d  %s::make(%s) -> "%s"', $relative, $component['line'], $component['class'], $component['name'], $label);
            }
        }
    }

    expect($untranslated)->toBe([], "Implicit Filament labels without a lang/ar.json entry; add ->label(__('...')) and an Arabic entry:\n".implode("\n", $untranslated));
});

test('the label scanner derives labels the way Filament does', function (): void {
    $component = static fn (string $class, string $name, array $chain = []): array => [
        'class' => $class,
        'name' => $name,
        'line' => 1,
        'chain' => $chain,
    ];

    expect(implicitLabelDerive($component('TextInput', 'sort_order')))->toBe('Sort order')
        ->and(implicitLabelDerive($component('TextColumn', 'customer.company_name')))->toBe('Customer')
        ->and(implicitLabelDerive($component('TextEntry', 'customer.company_name')))->toBe('Company name')
        ->and(implicitLabelDerive($component('Select', 'sla_calendar_id', [['method' => 'relationship', 'args' => "'slaCalendar', 'name'"]])))->toBe('Sla calendar')
        ->and(implicitLabelDerive($component('Select', 'sla_calendar_id')))->toBe('Sla calendar id')
        ->and(implicitLabelDerive($component('Toggle', 'is_active', [['method' => 'label', 'args' => "__ ( 'Active' )"]])))->toBeNull()
        ->and(implicitLabelDerive($component('Toggle', 'is_active', [['method' => 'label', 'args' => '']])))->toBe('Is active')
        ->and(implicitLabelDerive($component('Repeater', 'milestones', [['method' => 'hiddenLabel', 'args' => '']])))->toBeNull()
        ->and(implicitLabelDerive($component('Section', 'Rule')))->toBeNull();
});

test('the label scanner finds components and their explicit labels', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'lbl');
    file_put_contents($path, <<<'PHP'
        <?php
        return [
            TextInput::make('precedence')->numeric(),
            Toggle::make('is_active')
                // explicit label spans lines
                ->label(__('Active'))
                ->default(true),
            Forms\Components\Select::make('status')->options(['a' => fn () => 1])->label('Status'),
            Select::make($dynamic),
        ];
        PHP);

    try {
        $components = implicitLabelScanComponents($path);
    } finally {
        unlink($path);
    }

    expect(array_map(implicitLabelDerive(...), $components))
        ->toBe(['Precedence', null, null]);
});

test('every support resource exposes Arabic model labels', function (): void {
    $directories = [
        'SupportTeams', 'SupportSkills', 'SupportQueues', 'SupportRoutingRules', 'SupportAutomationRules',
        'ServiceAppointments', 'SupportEquipment', 'KnowledgeArticles', 'KnowledgeArticleCategories',
        'SlaPolicies', 'SlaCalendars', 'SupportServiceLevels', 'SupportEntitlements', 'SupportReports',
        'Tickets', 'MaintenanceRequests', 'ServiceRecords',
    ];

    $originalLocale = App::getLocale();
    App::setLocale('ar');

    try {
        $untranslated = [];

        foreach ($directories as $directory) {
            foreach (glob(app_path('Filament/Resources/'.$directory.'/*Resource.php')) ?: [] as $file) {
                /** @var class-string<Filament\Resources\Resource> $resource */
                $resource = 'App\\Filament\\Resources\\'.$directory.'\\'.basename($file, '.php');

                foreach ([$resource::getModelLabel(), $resource::getPluralModelLabel(), $resource::getNavigationLabel()] as $label) {
                    if (preg_match('/\p{Arabic}/u', $label) !== 1) {
                        $untranslated[] = $resource.' -> '.$label;
                    }
                }
            }
        }
    } finally {
        App::setLocale($originalLocale);
    }

    expect($untranslated)->toBe([]);
});
