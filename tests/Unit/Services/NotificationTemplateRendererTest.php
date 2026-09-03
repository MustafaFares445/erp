<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function wp210Template(
    NotificationEventKey $key = NotificationEventKey::InvoiceIssued,
    string $locale = 'en',
    NotificationChannel $channel = NotificationChannel::Mail,
    ?string $subject = 'Hello {{ name }}',
    string $body = 'Body {{ name }}',
    array $variables = ['name'],
    bool $active = true,
): NotificationTemplate {
    return NotificationTemplate::query()->create([
        'key' => $key->value,
        'locale' => $locale,
        'channel' => $channel,
        'subject' => $subject,
        'body' => $body,
        'variables' => $variables,
        'is_active' => $active,
    ]);
}

it('renders declared variables and exposes typed template casts', function (): void {
    $template = wp210Template();

    $rendered = app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::InvoiceIssued,
        'en',
        NotificationChannel::Mail,
        ['name' => 'Mustafa'],
    );

    expect($template->channel)->toBe(NotificationChannel::Mail)
        ->and($template->variables)->toBe(['name'])
        ->and($template->is_active)->toBeTrue()
        ->and($rendered->templateKey)->toBe(NotificationEventKey::InvoiceIssued->value)
        ->and($rendered->locale)->toBe('en')
        ->and($rendered->subject)->toBe('Hello Mustafa')
        ->and($rendered->body)->toBe('Body Mustafa');
});

it('renders a null declared variable as an empty string and supports subjectless templates', function (): void {
    wp210Template(subject: null);

    $rendered = app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::InvoiceIssued,
        'en',
        NotificationChannel::Mail,
        ['name' => null],
    );

    expect($rendered->subject)->toBeNull()
        ->and($rendered->body)->toBe('Body ');
});

it('rejects missing declared variables', function (): void {
    wp210Template();

    expect(fn () => app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::InvoiceIssued,
        'en',
        NotificationChannel::Mail,
        [],
    ))->toThrow(\DomainException::class, 'Missing notification template variables: name');
});

it('rejects undeclared variables', function (): void {
    wp210Template();

    expect(fn () => app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::InvoiceIssued,
        'en',
        NotificationChannel::Mail,
        ['name' => 'A', 'extra' => 'B'],
    ))->toThrow(\DomainException::class, 'Undeclared notification template variables: extra');
});

it('falls back to the application locale with an explicit warning', function (): void {
    wp210Template(locale: 'en');

    Log::shouldReceive('warning')
        ->once()
        ->with('Notification template locale fallback used.', [
            'key' => NotificationEventKey::InvoiceIssued->value,
            'requested_locale' => 'ar',
            'fallback_locale' => 'en',
            'channel' => NotificationChannel::Mail->value,
        ]);

    $rendered = app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::InvoiceIssued,
        'ar',
        NotificationChannel::Mail,
        ['name' => 'Fallback'],
    );

    expect($rendered->locale)->toBe('en')
        ->and($rendered->body)->toBe('Body Fallback');
});

it('refuses to render when no active template exists in either locale', function (): void {
    wp210Template(active: false);

    expect(fn () => app(NotificationTemplateRenderer::class)->render(
        NotificationEventKey::InvoiceIssued,
        'en',
        NotificationChannel::Mail,
        ['name' => 'A'],
    ))->toThrow(
        \DomainException::class,
        'No active notification template exists for [invoice.issued] [en] [mail].',
    );
});
