<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_shared_notification_dropdown_is_limited_to_ten_latest_items(): void
    {
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->createNotifications($user, 12);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications.latest', 10)
                ->where('notifications.unread_count', 12)
            );
    }

    public function test_notification_index_is_paginated_to_twenty_five_items(): void
    {
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->createNotifications($user, 30);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 25)
                ->where('notifications.meta.per_page', 25)
                ->where('notifications.meta.total', 30)
            );
    }

    private function createNotifications(User $user, int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'test',
                'title' => 'Notifikasi '.$index,
                'message' => 'Pesan '.$index,
                'data' => ['url' => route('dashboard')],
            ]);
        }
    }
}
