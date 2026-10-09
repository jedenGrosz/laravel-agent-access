<?php

declare(strict_types=1);

namespace Workbench\App\Notifications;

use LaravelAgentAccess\Contracts\NotificationRecipients;
use LaravelAgentAccess\Events\AccessEvent;
use LaravelAgentAccess\Identity\Principal;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\Grant;
use Workbench\App\Models\User;

final readonly class WorkflowRecipients implements NotificationRecipients
{
    public function __construct(private PrincipalDirectory $principals) {}

    public function recipients(AccessEvent $event): iterable
    {
        $requestId = $event->references['request_id'] ?? null;
        $grantId = $event->references['grant_id'] ?? null;
        $record = is_string($requestId) ? AccessRequest::query()->find($requestId)
            : (is_string($grantId) ? Grant::query()->find($grantId) : null);
        if ($record === null) {
            return;
        }
        $owner = $this->principals->find($record->principal_id, $record->principal_type);
        if ($event->type === 'ACCESS_REQUEST_CREATED') {
            foreach (User::role('finance_manager')->where('department', $owner->user->department)->where('is_suspended', false)->cursor() as $manager) {
                yield new Principal($manager, $record->principal_type);
            }
        } else {
            yield $owner;
        }
    }
}
