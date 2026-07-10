<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_theme_preference(): void
    {
        $user = User::factory()->create(['theme_preference' => 'system']);

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.theme.update'), [
                'theme_preference' => 'dark',
            ]);

        $response->assertRedirect(route('profile.edit'));

        $this->assertSame('dark', $user->refresh()->theme_preference);
    }

    public function test_theme_preference_must_be_valid(): void
    {
        $user = User::factory()->create(['theme_preference' => 'system']);

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.theme.update'), [
                'theme_preference' => 'sepia',
            ]);

        $response->assertSessionHasErrors('theme_preference');
        $this->assertSame('system', $user->refresh()->theme_preference);
    }
}
