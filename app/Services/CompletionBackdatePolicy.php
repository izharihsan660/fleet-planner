<?php

namespace App\Services;

use App\Models\SystemThreshold;
use Carbon\CarbonImmutable;

/**
 * Aturan mundurnya tanggal selesai. Batasnya dibaca dari system_thresholds
 * setiap kali dipakai — bukan di-cache di properti — supaya perubahan di
 * halaman Pengaturan Sistem langsung berlaku tanpa deploy ulang.
 */
class CompletionBackdatePolicy
{
    public const SELF_SERVICE_DAYS_KEY = 'backdate_self_service_days';

    public const MAX_DAYS_KEY = 'backdate_max_days';

    public const DEFAULT_SELF_SERVICE_DAYS = 30;

    public const DEFAULT_MAX_DAYS = 90;

    /**
     * Panjang minimum catatan untuk mundur di dalam batas self-service.
     */
    public const SELF_SERVICE_NOTE_MIN_LENGTH = 10;

    /**
     * Mundur di atas self-service perlu penjelasan yang lebih rinci.
     */
    public const EXTENDED_NOTE_MIN_LENGTH = 30;

    public function selfServiceDays(): int
    {
        return $this->threshold(self::SELF_SERVICE_DAYS_KEY, self::DEFAULT_SELF_SERVICE_DAYS);
    }

    public function maxDays(): int
    {
        return $this->threshold(self::MAX_DAYS_KEY, self::DEFAULT_MAX_DAYS);
    }

    public function daysBackdated(CarbonImmutable $completedDate): int
    {
        return max(0, (int) $completedDate->startOfDay()->diffInDays(CarbonImmutable::today(), false));
    }

    /**
     * Panjang catatan minimum untuk jarak mundur tertentu. 0 berarti catatan
     * tidak wajib (penyelesaian hari ini).
     */
    public function requiredNoteLength(int $daysBackdated): int
    {
        if ($daysBackdated <= 0) {
            return 0;
        }

        return $daysBackdated <= $this->selfServiceDays()
            ? self::SELF_SERVICE_NOTE_MIN_LENGTH
            : self::EXTENDED_NOTE_MIN_LENGTH;
    }

    public function exceedsMaxDays(int $daysBackdated): bool
    {
        return $daysBackdated > $this->maxDays();
    }

    /**
     * Dipakai frontend untuk menandai form sebelum request dikirim.
     *
     * @return array{self_service_days: int, max_days: int, self_service_note_min_length: int, extended_note_min_length: int}
     */
    public function toArray(): array
    {
        return [
            'self_service_days' => $this->selfServiceDays(),
            'max_days' => $this->maxDays(),
            'self_service_note_min_length' => self::SELF_SERVICE_NOTE_MIN_LENGTH,
            'extended_note_min_length' => self::EXTENDED_NOTE_MIN_LENGTH,
        ];
    }

    private function threshold(string $key, int $default): int
    {
        $value = SystemThreshold::query()->where('key', $key)->value('value');

        return $value === null ? $default : max(0, (int) $value);
    }
}
