<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SystemThreshold;
use App\Models\User;
use Database\Seeders\SystemThresholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SystemThresholdValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(SystemThresholdSeeder::class);
    }

    public function test_valid_backdate_window_is_accepted(): void
    {
        $this->updateThreshold('backdate_max_days', '90')->assertSessionHasNoErrors();
        $this->updateThreshold('backdate_self_service_days', '30')->assertSessionHasNoErrors();

        $this->assertSame('90', $this->valueOf('backdate_max_days'));
        $this->assertSame('30', $this->valueOf('backdate_self_service_days'));
    }

    public function test_self_service_above_max_is_rejected(): void
    {
        $this->updateThreshold('backdate_self_service_days', '120')
            ->assertSessionHasErrors(['value' => 'Batas isi sendiri tidak boleh lebih besar dari batas maksimal.']);

        $this->assertSame('30', $this->valueOf('backdate_self_service_days'));
    }

    public function test_self_service_equal_to_max_is_accepted(): void
    {
        $this->updateThreshold('backdate_self_service_days', '90')->assertSessionHasNoErrors();

        $this->assertSame('90', $this->valueOf('backdate_self_service_days'));
    }

    public function test_pair_submitted_across_two_saves_is_rejected_on_the_invalid_combination(): void
    {
        // Form Pengaturan Sistem menyimpan satu threshold per request, jadi
        // "keduanya sekaligus" berarti simpanan kedua yang membuat pasangannya
        // tidak masuk akal — dan itu yang harus ditolak.
        $this->updateThreshold('backdate_max_days', '90')->assertSessionHasNoErrors();

        $this->updateThreshold('backdate_self_service_days', '100')
            ->assertSessionHasErrors(['value' => 'Batas isi sendiri tidak boleh lebih besar dari batas maksimal.']);

        $this->assertSame('30', $this->valueOf('backdate_self_service_days'));
        $this->assertSame('90', $this->valueOf('backdate_max_days'));
    }

    public function test_lowering_max_below_self_service_is_rejected_too(): void
    {
        $this->updateThreshold('backdate_max_days', '20')
            ->assertSessionHasErrors(['value' => 'Batas isi sendiri tidak boleh lebih besar dari batas maksimal.']);

        $this->assertSame('90', $this->valueOf('backdate_max_days'));
    }

    public function test_existing_preview_threshold_ordering_still_applies(): void
    {
        // upcoming > ancang-ancang > warning. Menaikkan warning melewati
        // ancang-ancang harus tetap ditolak seperti sebelumnya.
        $this->updateThreshold('warning_days', '30')
            ->assertSessionHasErrors(['value' => 'Urutan threshold days harus: upcoming > ancang-ancang > warning.']);

        $this->updateThreshold('warning_km', '5000')
            ->assertSessionHasErrors(['value' => 'Urutan threshold km harus: upcoming > ancang-ancang > warning.']);

        $this->assertSame('7', $this->valueOf('warning_days'));
        $this->assertSame('500', $this->valueOf('warning_km'));
    }

    public function test_unrelated_threshold_saves_normally(): void
    {
        $this->updateThreshold('min_inspection_data', '5')->assertSessionHasNoErrors();
        $this->updateThreshold('warning_days', '5')->assertSessionHasNoErrors();

        $this->assertSame('5', $this->valueOf('min_inspection_data'));
        $this->assertSame('5', $this->valueOf('warning_days'));
    }

    private function updateThreshold(string $key, string $value): TestResponse
    {
        $threshold = SystemThreshold::query()->where('key', $key)->firstOrFail();
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        return $this->actingAs($superadmin)->put(route('system-thresholds.update', $threshold), [
            'key' => $key,
            'value' => $value,
            'description' => $threshold->description,
        ]);
    }

    private function valueOf(string $key): string
    {
        return SystemThreshold::query()->where('key', $key)->value('value');
    }
}
