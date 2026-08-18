<?php

namespace App\Console\Commands;

use App\Services\FleetNotificationService;
use Illuminate\Console\Command;

class SendKmInputSummaryNotifications extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:send-km-input-summary';

    /**
     * @var string
     */
    protected $description = 'Kirim notifikasi ringkasan input KM periodik sesuai interval pengaturan sistem';

    public function handle(FleetNotificationService $notificationService): int
    {
        $sentCount = $notificationService->sendKmInputPeriodicSummaries();

        $this->info("{$sentCount} notifikasi ringkasan input KM berhasil dikirim.");

        return self::SUCCESS;
    }
}
