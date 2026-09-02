<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VehicleCategory;
use App\Models\Site;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_plate_search_ignores_spacing_and_letter_case(): void
    {
        $site = $this->site('Balikpapan');
        $target = $this->unit($site, 'KT 8404 YR');
        $this->unit($site, 'DA 1550 OA');

        foreach (['kt 8404', 'KT8404', '8404', 'kt8404 '] as $term) {
            $this->actingAs($this->superadmin())
                ->get(route('units.index', ['search' => $term]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Units/Index')
                    ->has('units.data', 1)
                    ->where('units.data.0.id', $target->id)
                    ->where('filters.search', trim($term))
                );
        }
    }

    public function test_search_also_matches_customer_brand_and_type(): void
    {
        $site = $this->site('Balikpapan');
        $target = $this->unit($site, 'KT 1111 AA', ['customer' => 'PT Sinar Abadi', 'brand' => 'Isuzu', 'type' => 'Elf']);
        $this->unit($site, 'KT 2222 BB', ['customer' => 'PT Lain', 'brand' => 'Toyota', 'type' => 'Hilux']);

        foreach (['sinar', 'isuzu', 'elf'] as $term) {
            $this->actingAs($this->superadmin())
                ->get(route('units.index', ['search' => $term]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('units.data', 1)
                    ->where('units.data.0.id', $target->id)
                );
        }
    }

    public function test_site_status_and_category_filters_stack(): void
    {
        $balikpapan = $this->site('Balikpapan');
        $samarinda = $this->site('Samarinda');

        $target = $this->unit($balikpapan, 'KT 1000 AA', ['status' => 'breakdown', 'vehicle_category' => VehicleCategory::Bus->value]);
        $this->unit($balikpapan, 'KT 2000 BB', ['status' => 'active', 'vehicle_category' => VehicleCategory::Bus->value]);
        $this->unit($samarinda, 'KT 3000 CC', ['status' => 'breakdown', 'vehicle_category' => VehicleCategory::Bus->value]);
        $this->unit($balikpapan, 'KT 4000 DD', ['status' => 'breakdown', 'vehicle_category' => VehicleCategory::PickupSuv->value]);

        $this->actingAs($this->superadmin())
            ->get(route('units.index', [
                'site_id' => $balikpapan->id,
                'status' => 'breakdown',
                'vehicle_category' => VehicleCategory::Bus->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('units.data', 1)
                ->where('units.data.0.id', $target->id)
                ->where('units.meta.total', 1)
                ->where('totalUnits', 4)
            );
    }

    public function test_list_is_sorted_by_plate_and_exposes_filter_options(): void
    {
        $site = $this->site('Balikpapan');
        $this->unit($site, 'KT 9999 ZZ');
        $this->unit($site, 'DA 1111 AA');

        $this->actingAs($this->superadmin())
            ->get(route('units.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('units.data.0.current_plate', 'DA 1111 AA')
                ->where('units.data.1.current_plate', 'KT 9999 ZZ')
                ->has('sites', 1)
                ->has('vehicleCategories', 3)
                ->where('filters.search', '')
            );
    }

    public function test_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('units.index', ['status' => 'entah']))
            ->assertSessionHasErrors('status');
    }

    /**
     * Master Data Unit hanya untuk Superadmin dan Spv HO — keduanya harus bisa
     * memakai filter yang sama.
     */
    public function test_spv_ho_can_filter_and_other_roles_cannot_open_the_page(): void
    {
        $balikpapan = $this->site('Balikpapan', 'Kalimantan');
        $makassar = $this->site('Makassar', 'Sulawesi');
        $target = $this->unit($balikpapan, 'KT 5000 AA');
        $this->unit($makassar, 'DD 6000 BB');

        $this->actingAs(User::factory()->create(['role' => UserRole::SpvHo]))
            ->get(route('units.index', ['site_id' => $balikpapan->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('units.data', 1)
                ->where('units.data.0.id', $target->id)
            );

        foreach ([UserRole::PlannerArea, UserRole::Mekanik] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'site_id' => $balikpapan->id]))
                ->get(route('units.index'))
                ->assertForbidden();
        }
    }

    private function site(string $name, string $region = 'Kalimantan'): Site
    {
        return Site::query()->create(['name' => $name, 'region' => $region]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function unit(Site $site, string $plate, array $overrides = []): Unit
    {
        return Unit::query()->create(array_merge([
            'site_id' => $site->id,
            'customer' => 'PT Test',
            'current_plate' => $plate,
            'type' => 'Hilux',
            'brand' => 'Toyota',
            'vehicle_category' => VehicleCategory::PickupSuv->value,
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ], $overrides));
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => UserRole::Superadmin]);
    }
}
