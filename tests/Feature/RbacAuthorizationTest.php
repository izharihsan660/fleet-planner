<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_is_cast_and_site_relation_is_available(): void
    {
        $site = Site::query()->create([
            'name' => 'Site Balikpapan',
            'region' => 'Kalimantan Timur',
        ]);

        $user = User::factory()->create([
            'role' => UserRole::PlannerArea,
            'site_id' => $site->id,
        ]);

        $this->assertTrue($user->hasRole(UserRole::PlannerArea));
        $this->assertTrue($user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]));
        $this->assertTrue($user->site->is($site));
    }

    public function test_gates_match_expected_roles(): void
    {
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);
        $plannerHo = User::factory()->create(['role' => UserRole::SpvHo]);
        $mekanik = User::factory()->create(['role' => UserRole::Mekanik]);

        $this->assertTrue(Gate::forUser($superadmin)->allows('manage-users'));
        $this->assertFalse(Gate::forUser($plannerHo)->allows('manage-users'));

        $this->assertTrue(Gate::forUser($superadmin)->allows('manage-master-data'));
        $this->assertTrue(Gate::forUser($plannerHo)->allows('manage-master-data'));
        $this->assertFalse(Gate::forUser($mekanik)->allows('manage-master-data'));
    }

    public function test_role_middleware_allows_matching_roles_only(): void
    {
        Route::middleware(['web', 'auth', 'role:superadmin,spv_ho'])->get('/rbac-test-route', fn () => 'OK');

        $plannerHo = User::factory()->create(['role' => UserRole::SpvHo]);
        $mekanik = User::factory()->create(['role' => UserRole::Mekanik]);

        $this->actingAs($plannerHo)->get('/rbac-test-route')->assertOk();
        $this->actingAs($mekanik)->get('/rbac-test-route')->assertForbidden();
    }

    public function test_authenticated_user_role_is_shared_to_inertia(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Superadmin,
            'site_id' => null,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $user->id)
                ->where('auth.user.role', UserRole::Superadmin->value)
                ->where('auth.user.site_id', null)
            );
    }

    public function test_dashboard_shares_overdue_banner_count(): void
    {
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overdueBanner.count', 0)
                ->where('overdueBanner.threshold', 20)
            );
    }

    public function test_dashboard_copy_is_role_aware_and_indonesian(): void
    {
        $dashboardSource = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringNotContainsString("You're logged in!", $dashboardSource);
        $this->assertStringContainsString('Kamu sudah masuk.', $dashboardSource);
        $this->assertStringContainsString('planner_area', $dashboardSource);
        $this->assertStringContainsString('Daftar Kerja', $dashboardSource);
    }

    public function test_reports_can_open_overdue_tab_from_query_string(): void
    {
        $user = User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($user)
            ->get(route('reports.index', ['tab' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.tab', 'overdue')
                ->where('permissions.default_tab', 'overdue')
            );
    }

    public function test_seeded_role_user_can_login_and_receives_role_prop(): void
    {
        $this->seed(RoleUserSeeder::class);

        $this->post('/login', [
            'email' => 'superadmin@example.com',
            'password' => '123123',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.email', 'superadmin@example.com')
                ->where('auth.user.role', UserRole::Superadmin->value)
                ->where('auth.user.site_id', null)
            );
    }
}
