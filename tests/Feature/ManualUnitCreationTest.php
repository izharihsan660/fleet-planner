<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VehicleCategory;
use App\Models\Site;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualUnitCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_creation_with_zero_odometer_marks_reading_as_unknown(): void
    {
        $site = $this->createSite();

        $this->actingAs($this->createSuperadmin())
            ->post(route('units.store'), $this->validUnitData($site, ['current_odo' => 0]))
            ->assertRedirect(route('units.index'));

        $unit = Unit::query()->where('current_plate', 'KT 1234 AA')->firstOrFail();

        $this->assertSame(0, $unit->current_odo);
        $this->assertFalse($unit->has_odometer_reading);
    }

    public function test_manual_creation_with_blank_odometer_marks_reading_as_unknown(): void
    {
        $site = $this->createSite();

        $this->actingAs($this->createSuperadmin())
            ->post(route('units.store'), $this->validUnitData($site, ['current_odo' => '']))
            ->assertRedirect(route('units.index'));

        $unit = Unit::query()->where('current_plate', 'KT 1234 AA')->firstOrFail();

        $this->assertSame(0, $unit->current_odo);
        $this->assertFalse($unit->has_odometer_reading);
    }

    public function test_manual_creation_with_positive_odometer_marks_reading_as_confirmed(): void
    {
        $site = $this->createSite();

        $this->actingAs($this->createSuperadmin())
            ->post(route('units.store'), $this->validUnitData($site, ['current_odo' => 12345]))
            ->assertRedirect(route('units.index'));

        $unit = Unit::query()->where('current_plate', 'KT 1234 AA')->firstOrFail();

        $this->assertSame(12345, $unit->current_odo);
        $this->assertTrue($unit->has_odometer_reading);
    }

    private function createSite(): Site
    {
        return Site::query()->create([
            'name' => 'Balikpapan',
            'region' => 'Kalimantan',
        ]);
    }

    private function createSuperadmin(): User
    {
        return User::factory()->create(['role' => UserRole::Superadmin]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUnitData(Site $site, array $overrides = []): array
    {
        return array_merge([
            'site_id' => $site->id,
            'customer' => 'PT Test',
            'current_plate' => 'KT 1234 AA',
            'type' => 'Hilux',
            'brand' => 'Toyota',
            'vehicle_category' => VehicleCategory::PickupSuv->value,
            'year' => 2024,
            'current_odo' => 0,
            'status' => 'active',
        ], $overrides);
    }
}
