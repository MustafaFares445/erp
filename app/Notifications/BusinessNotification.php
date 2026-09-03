<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

final class BusinessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $deliveryId,
        public NotificationChannel $channel,
        public ?string $subject,
        public string $body,
        /** @var list<array{path:string,name?:string,mime?:string}> */
        public array $attachments = [],
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return match ($this->channel) {
            NotificationChannel::Mail => ['mail'],
            NotificationChannel::Database => ['database'],
            NotificationChannel::Sms, NotificationChannel::Whatsapp => [],
        };
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)->line($this->body);

        if ($this->subject !== null && $this->subject !== '') {
            $message->subject($this->subject);
        }

        foreach ($this->attachments as $attachment) {
            $path = $attachment['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $options = [];
            $name = $attachment['name'] ?? null;
            $mime = $attachment['mime'] ?? null;

            if (is_string($name) && $name !== '') {
                $options['as'] = $name;
            }

            if (is_string($mime) && $mime !== '') {
                $options['mime'] = $mime;
            }

            $message->attach($path, $options);
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'delivery_id' => $this->deliveryId,
            'subject' => $this->subject,
            'body' => $this->body,
            'attachments' => $this->attachments,
        ];
    }

    public function failed(Throwable $exception): void
    {
        NotificationDelivery::query()
            ->whereKey($this->deliveryId)
            ->update([
                'status' => NotificationDeliveryStatus::Failed->value,
                'error' => mb_substr($exception->getMessage(), 0, 500),
                'failed_at' => now(),
            ]);
    }
}
