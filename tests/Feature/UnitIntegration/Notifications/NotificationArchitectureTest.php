<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('keeps direct mail and notification facades inside the notification delivery boundary', function (): void {
    $violations = [];
    $dispatcher = str_replace('\\', '/', app_path('Services/Notifications/NotificationDispatcher.php'));

    foreach (File::allFiles(app_path('Services')) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if ($path === $dispatcher) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, Mail::class)
            || str_contains($source, Notification::class)) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBe([]);
});

it('removes the legacy invoice mailable and keeps the invoice job on the dispatcher', function (): void {
    expect(file_exists(app_path('Mail/InvoiceMail.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/emails/invoice.blade.php')))->toBeFalse();

    $source = (string) file_get_contents(app_path('Jobs/SendInvoiceEmail.php'));

    expect($source)
        ->toContain('NotificationDispatcher')
        ->not->toContain('Mail::')
        ->not->toContain('InvoiceMail');
});
