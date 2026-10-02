<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$requiredFiles = [
    'README.md',
    'AGENTS.md',
    'CLAUDE.md',
    'specs/README.md',
    'Docs/README.md',
    'Docs/product/PRODUCT_OVERVIEW.md',
    'Docs/product/BUSINESS_FLOWS.md',
    'Docs/product/ROLES_AND_PERMISSIONS.md',
    'Docs/product/GLOSSARY.md',
    'Docs/architecture/SYSTEM_OVERVIEW.md',
    'Docs/architecture/DOMAIN_MAP.md',
    'Docs/architecture/DATA_ARCHITECTURE.md',
    'Docs/architecture/INTEGRATIONS.md',
    'Docs/architecture/SECURITY.md',
    'Docs/onboarding/LOCAL_SETUP.md',
    'Docs/onboarding/DEVELOPMENT_WORKFLOW.md',
    'Docs/onboarding/TESTING_AND_QUALITY.md',
    'Docs/onboarding/AGENT_WORKFLOW.md',
    'Docs/reference/CONFIGURATION.md',
    'Docs/reference/COMMANDS.md',
    'Docs/reference/STATUS_AND_TRANSITIONS.md',
    'Docs/reference/API.md',
    'Docs/reference/DATA_MODEL.md',
    'Docs/operations/DEPLOYMENT.md',
    'Docs/operations/INFRASTRUCTURE.md',
    'Docs/operations/MONITORING.md',
    'Docs/operations/BACKUP_AND_RECOVERY.md',
    'Docs/adr/README.md',
    '.specify/memory/constitution.md',
];

$domains = [
    'identity',
    'settings',
    'catalog',
    'inventory',
    'purchasing',
    'sales',
    'payments',
    'accounting',
    'logistics',
    'crm',
    'employees',
    'support',
    'notifications',
    'reporting',
];

foreach ($domains as $domain) {
    $requiredFiles[] = "Docs/domains/{$domain}/README.md";
}

foreach ($requiredFiles as $relativePath) {
    if (! is_file($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
        $errors[] = "Missing required canonical file: {$relativePath}";
    }
}

$indexPath = $root.DIRECTORY_SEPARATOR.'Docs'.DIRECTORY_SEPARATOR.'README.md';
$index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';

foreach ($domains as $domain) {
    $expectedLink = "domains/{$domain}/README.md";

    if (! str_contains($index, $expectedLink)) {
        $errors[] = "Docs/README.md does not link canonical domain: {$expectedLink}";
    }
}

$forbiddenReferences = [
    'Docs/PRD.md',
    'Docs/SDD.md',
    'Docs/IMPLEMENTATION_PLAN.md',
    'Docs/CROSS_MODULE_BUSINESS_FLOWS.md',
    'Docs/ERP_DOMAIN_MODEL.md',
    'Docs/EXPECTED_BUSINESS_SCENARIOS.md',
    'Docs/database/ERD.md',
    'Docs/database/DFD.md',
    'Docs/diagrams/SEQUENCE_DIAGRAMS.md',
    'Docs/api/API_CONTRACT.md',
    'PROJECT-SETUP-GUIDE.md',
    'product-specs/mobile-apps',
];

$markdownFiles = [];

foreach ([
    $root.DIRECTORY_SEPARATOR.'Docs',
    $root.DIRECTORY_SEPARATOR.'README.md',
    $root.DIRECTORY_SEPARATOR.'AGENTS.md',
    $root.DIRECTORY_SEPARATOR.'CLAUDE.md',
    $root.DIRECTORY_SEPARATOR.'specs'.DIRECTORY_SEPARATOR.'README.md',
    $root.DIRECTORY_SEPARATOR.'.specify'.DIRECTORY_SEPARATOR.'memory'.DIRECTORY_SEPARATOR.'constitution.md',
] as $source) {
    if (is_file($source)) {
        $markdownFiles[] = $source;

        continue;
    }

    if (! is_dir($source)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
            $markdownFiles[] = $file->getPathname();
        }
    }
}

$markdownFiles = array_values(array_unique($markdownFiles));

foreach ($markdownFiles as $file) {
    $contents = (string) file_get_contents($file);
    $relativeFile = str_replace('\\', '/', mb_substr($file, mb_strlen($root) + 1));

    foreach ($forbiddenReferences as $forbidden) {
        if (str_contains($contents, $forbidden)) {
            $errors[] = "{$relativeFile} references removed legacy path: {$forbidden}";
        }
    }

    if (preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $contents, $matches) !== false) {
        foreach ($matches[1] as $link) {
            if (
                $link === ''
                || str_starts_with($link, '#')
                || preg_match('/^[a-z][a-z0-9+.-]*:/i', $link) === 1
            ) {
                continue;
            }

            $pathPart = explode('#', $link, 2)[0];

            if ($pathPart === '') {
                continue;
            }

            $decoded = rawurldecode($pathPart);
            $target = dirname($file).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $decoded);

            if (! file_exists($target)) {
                $errors[] = "{$relativeFile} has broken relative link: {$link}";
            }
        }
    }
}

$plansDirectory = $root.DIRECTORY_SEPARATOR.'Docs'.DIRECTORY_SEPARATOR.'plans';

if (is_dir($plansDirectory)) {
    foreach (glob($plansDirectory.DIRECTORY_SEPARATOR.'*.md') ?: [] as $plan) {
        $contents = (string) file_get_contents($plan);
        $relative = str_replace('\\', '/', mb_substr($plan, mb_strlen($root) + 1));

        if (! preg_match('/\A---\R.*?^status:\s*active-plan\s*$.*?^owner:\s*\S+.*?^last_verified:\s*\d{4}-\d{2}-\d{2}\s*$.*?---\R/ms', $contents)) {
            $errors[] = "{$relative} must begin with active-plan metadata (status, owner, last_verified).";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, 'Documentation checks failed:'.PHP_EOL);

    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}".PHP_EOL);
    }

    exit(1);
}

fwrite(STDOUT, sprintf(
    'Documentation checks passed (%d Markdown files, %d canonical domains).%s',
    count($markdownFiles),
    count($domains),
    PHP_EOL,
));
