<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Notifications;

use LaravelAgentAccess\Contracts\NotificationRecipients;
use LaravelAgentAccess\Events\AccessEvent;

final class EmptyNotificationRecipients implements NotificationRecipients
{
    public function recipients(AccessEvent $event): iterable
    {
        return [];
    }
}
