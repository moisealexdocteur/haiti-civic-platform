<?php

namespace App\Commands;

use App\Services\NotificationDeliveryService;
use App\Services\NotificationDigestService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class NotificationsWork extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'notifications:work';
    protected $description = 'Traite la file des notifications et prépare les rapports quotidiens.';
    protected $options = [
        '--once' => 'Traite un seul lot puis termine.',
        '--limit' => 'Nombre maximal de messages par lot (1 à 100).',
    ];

    public function run(array $params)
    {
        $once = CLI::getOption('once') !== null;
        $limit = max(1, min(100, (int) (CLI::getOption('limit') ?: 25)));
        $lastDigestDate = null;
        $lastDigestAttemptAt = 0;

        do {
            try {
                $summary = (new NotificationDeliveryService())->dispatchBatch($limit);
                if ($once) {
                    CLI::write(json_encode($summary, JSON_UNESCAPED_SLASHES), 'green');
                }

                $date = gmdate('Y-m-d');
                $digestDue = $lastDigestDate !== $date
                    && ($once || (
                        gmdate('Hi') >= '1200'
                        && time() - $lastDigestAttemptAt >= 300
                    ));

                if ($digestDue) {
                    $lastDigestAttemptAt = time();
                    $digest = (new NotificationDigestService())
                        ->queueForAllTenants($date);

                    if ($digest['failed'] === 0) {
                        $lastDigestDate = $date;
                    }
                }
            } catch (Throwable $exception) {
                CLI::error('Notification worker error: ' . $exception::class);
                log_message('error', 'Notification worker error: {type}', ['type' => $exception::class]);
            }

            if (! $once) {
                sleep(5);
            }
        } while (! $once);
    }
}
