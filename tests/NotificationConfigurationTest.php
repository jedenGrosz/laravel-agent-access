<?php

declare(strict_types=1);

namespace Tests;

use LaravelAgentAccess\ConfigurationValidator;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotificationConfigurationTest extends TestCase
{
    #[DataProvider('invalidSettings')]
    public function test_enabled_notifications_reject_invalid_settings(string $key, mixed $value): void
    {
        config(['agent-access.notifications.enabled' => true, 'agent-access.notifications.'.$key => $value]);
        $this->expectException(\LogicException::class);
        app(ConfigurationValidator::class)->validate();
    }

    public static function invalidSettings(): iterable
    {
        yield 'blank queue name' => ['queue', '   '];
        yield 'queue list instead of a name' => ['queue', ['default']];
        yield 'event string instead of a list' => ['events', 'ACCESS_REQUEST_CREATED'];
        yield 'empty event name' => ['events', ['ACCESS_REQUEST_CREATED', '']];
        yield 'non-string event name' => ['events', ['ACCESS_REQUEST_CREATED', 42]];
        yield 'event map instead of a list' => ['events', ['created' => 'ACCESS_REQUEST_CREATED']];
    }

    public function test_disabled_adapter_does_not_require_valid_notification_settings(): void
    {
        config(['agent-access.notifications.enabled' => false, 'agent-access.notifications.queue' => [], 'agent-access.notifications.events' => null]);
        app(ConfigurationValidator::class)->validate();
        $this->artisan('agent-access:validate')->assertSuccessful();
    }

    public function test_empty_event_list_and_custom_event_names_remain_valid(): void
    {
        config(['agent-access.notifications.enabled' => true, 'agent-access.notifications.events' => []]);
        app(ConfigurationValidator::class)->validate();
        config(['agent-access.notifications.events' => ['HOST_ACCESS_REVIEWED']]);
        app(ConfigurationValidator::class)->validate();
        $this->artisan('agent-access:validate')->assertSuccessful();
    }
}
