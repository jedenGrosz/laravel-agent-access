<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Notifications;

use Illuminate\Notifications\Notification;
use LaravelAgentAccess\Events\AccessEvent;
use LaravelAgentAccess\Identity\Principal;
use Ramsey\Uuid\Uuid;

final class AccessWorkflowNotification extends Notification
{
    public function __construct(private readonly AccessEvent $event, Principal $recipient)
    {
        $identity = json_encode([$event->eventId ?? [$event->type, $event->correlationId, $event->references], $recipient->type, $recipient->id], JSON_THROW_ON_ERROR);
        $this->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'laravel-agent-access/'.$identity)->toString();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['schema_version' => 1, 'event' => $this->event->type, 'correlation_id' => $this->event->correlationId,
            'references' => array_intersect_key($this->event->references, array_flip(['request_id', 'grant_id']))];
    }
}
