<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class NotificationConnectionUser extends User
{
    protected $connection = 'notification_fixture';

    protected $table = 'users';
}
