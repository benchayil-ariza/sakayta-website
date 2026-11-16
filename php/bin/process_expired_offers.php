<?php declare(strict_types=1);

namespace Sakayta\Bin;

require __DIR__ . '/../autoload.php';

use Sakayta\Config\Config;
use Sakayta\Ride\RideService;

/** Explicit CLI entry point; including this file does not run the processor. */
function runExpiredOfferProcessor(): int
{
    Config::bootstrap();

    try {
        $summary = (new RideService())->processExpiredOffers();
        echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return ($summary['failed'] ?? 0) > 0 ? 1 : 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Expired-offer processing failed: ' . get_class($e) . ' (' . (string) $e->getCode() . ').' . PHP_EOL);
        return 1;
    }
}

$invokedFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
$workerFile = realpath(__FILE__);
if (PHP_SAPI === 'cli' && $invokedFile !== '' && $workerFile !== false && realpath($invokedFile) === $workerFile) {
    exit(runExpiredOfferProcessor());
}
