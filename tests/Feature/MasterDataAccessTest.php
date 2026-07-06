<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PlanningItemSeeder;
use Database\Seeders\SystemThresholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_create_planning_items_and_thresholds(): void
    {
        $this->seed([PlanningItemSeeder::class, SystemThresholdSeeder::class]);

        $this->assertSame(18, PlanningItem::query()->count());
        $this->assertSame('500', SystemThreshold::query()->where('key', 'warning_km')->value('value'));
        $this->assertSame('14', SystemThreshold::query()->where('key', 'ancang_ancang_days')->value('value'));
        $this->assertSame('2000', SystemThreshold::query()->where('key', 'upcoming_km')->value('value'));
    }

    public function test_preview_threshold_order_must_stay_above_warning_threshold(): void
    {
        $this->seed(SystemThresholdSeeder::class);
        $plannerHo = User::factory()->create(['role' => UserRole::PlannerHo]);
        $threshold = SystemThreshold::query()->where('key', 'ancang_ancang_days')->firstOrFail();

        $this->actingAs($plannerHo)
            ->patch(route('system-thresholds.update', $threshold), [
                'key' => 'ancang_ancang_days',
                'value' => '7',
                'description' => $threshold->description,
            ])
            ->assertSessionHasErrors('value');

        $this->assertSame('14', $threshold->refresh()->value);
    }

    public function test_unit_plate_history_is_created_and_updated_when_plate_changes(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'KT 1000 AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 100,
            'status' => 'active',
        ]);

        $this->assertTrue($unit->is_warranty);
        $this->assertDatabaseHas('unit_plate_histories', ['unit_id' => $unit->id, 'plate_number' => 'KT 1000 AA', 'active_until' => null]);

        $unit->update(['current_plate' => 'KT 2000 BB']);

        $this->assertDatabaseHas('unit_plate_histories', ['unit_id' => $unit->id, 'plate_number' => 'KT 1000 AA', 'active_until' => now()->startOfDay()->toDateTimeString()]);
        $this->assertDatabaseHas('unit_plate_histories', ['unit_id' => $unit->id, 'plate_number' => 'KT 2000 BB', 'active_until' => null]);
    }

    public function test_master_data_pages_are_accessible_by_expected_roles(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);
        $plannerHo = User::factory()->create(['role' => UserRole::PlannerHo]);
        $mekanik = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        foreach (['sites.index', 'units.index'] as $routeName) {
            $this->actingAs($mekanik)->get(route($routeName))->assertForbidden();
        }

        foreach (['sites.index', 'units.index', 'sites.create', 'units.create', 'planning-items.index', 'system-thresholds.index'] as $routeName) {
            $this->actingAs($plannerHo)->get(route($routeName))->assertOk();
        }

        $this->actingAs($mekanik)->get(route('planning-items.index'))->assertForbidden();
        $this->actingAs($superadmin)->get(route('users.index'))->assertOk();
        $this->actingAs($plannerHo)->get(route('users.index'))->assertForbidden();
    }
}
