<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\UserPresence;
use Carbon\Carbon;
use Tests\TestCase;

class UserPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['presence.online_status_timeout' => 120]);
        Carbon::setTestNow('2026-09-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_null_user_is_offline(): void
    {
        $this->assertFalse(UserPresence::isOnline(null));
    }

    public function test_null_last_seen_is_offline(): void
    {
        $user = new User;
        $user->last_seen_at = null;

        $this->assertFalse(UserPresence::isOnline($user));
    }

    public function test_recent_last_seen_is_online(): void
    {
        $user = new User;
        $user->last_seen_at = now()->subSeconds(120);

        $this->assertTrue(UserPresence::isOnline($user));
    }

    public function test_old_last_seen_is_offline(): void
    {
        $user = new User;
        $user->last_seen_at = now()->subSeconds(121);

        $this->assertFalse(UserPresence::isOnline($user));
    }

    public function test_timeout_is_read_from_config(): void
    {
        $this->assertSame(120, UserPresence::timeoutSeconds());
    }
}
