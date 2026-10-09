<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Notifications;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use LaravelAgentAccess\Contracts\NotificationRecipients;
use LaravelAgentAccess\Contracts\PrincipalResolver;
use LaravelAgentAccess\Events\AccessEvent;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;

final class NotifyAccessEvent implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public function __construct(private readonly NotificationRecipients $recipients, private readonly AccessRequestService $requests,
        private readonly GrantService $grants, private readonly PrincipalResolver $principals) {}

    public function shouldQueue(AccessEvent $event): bool
    {
        return config('agent-access.notifications.enabled') && in_array($event->type, config('agent-access.notifications.events', []), true);
    }

    public function viaQueue(): string
    {
        return (string) config('agent-access.notifications.queue', 'default');
    }

    public function handle(AccessEvent $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }
        foreach ($this->recipients->recipients($event) as $recipient) {
            try {
                if (! $this->principals->resolve($recipient->user)->equals($recipient)) {
                    continue;
                }
                $recipient->refresh();
                $context = new ExecutionContext($recipient);
                $requestId = $event->references['request_id'] ?? null;
                $grantId = $event->references['grant_id'] ?? null;
                if (is_string($requestId)) {
                    $request = AccessRequest::query()->find($requestId);
                    $visible = $request !== null && ($this->requests->owns($context, $request) || $this->requests->canApprove($context, $request));
                } elseif (is_string($grantId)) {
                    $grant = Grant::query()->find($grantId);
                    $visible = $grant !== null && $this->grants->canManage($context, $grant);
                } else {
                    $visible = false;
                }
            } catch (AuthenticationException|ModelNotFoundException) {
                continue;
            }
            if (! $visible) {
                continue;
            }
            if (! method_exists($recipient->user, 'notify')) {
                throw new \LogicException('Notification recipients must use Laravel Notifiable.');
            }
            $notification = new AccessWorkflowNotification($event, $recipient);
            if (DatabaseNotification::query()->whereKey($notification->id)->exists()) {
                continue;
            }
            try {
                $recipient->user->notify($notification);
            } catch (UniqueConstraintViolationException $exception) {
                if (DatabaseNotification::query()->find($notification->id) === null) {
                    throw $exception;
                }
            }
        }
    }
}
