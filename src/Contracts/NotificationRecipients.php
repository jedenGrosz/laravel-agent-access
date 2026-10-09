<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Contracts;

use LaravelAgentAccess\Events\AccessEvent;
use LaravelAgentAccess\Identity\Principal;

interface NotificationRecipients
{
    /** Host selects candidates; the queued listener rechecks current record visibility.
     * @return iterable<Principal> */
    public function recipients(AccessEvent $event): iterable;
}
