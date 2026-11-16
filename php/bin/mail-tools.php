<?php declare(strict_types=1);

// Usage (from the project folder):
//   php php\bin\mail-tools.php test you@gmail.com                      one test email through Brevo
//   php php\bin\mail-tools.php receipt r_123_abc                       send the receipt emails of one ride again
//   php php\bin\mail-tools.php preview otp|reset|changed               save an email as php\bin\preview.html (nothing is sent)
//   php php\bin\mail-tools.php preview receipt r_123_abc passenger|driver
require __DIR__ . '/../autoload.php';

use Sakayta\Config\Config;
use Sakayta\Mail\BrevoMailer;
use Sakayta\Mail\MailTemplates;
use Sakayta\Mail\ReceiptMailer;

Config::bootstrap();
$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';

if ($cmd === 'test' && filter_var($arg, FILTER_VALIDATE_EMAIL) !== false) {
    $res = BrevoMailer::send($arg, '', 'SakayTa test email', '<p>Hello from SakayTa. Brevo is working.</p>', 'Hello from SakayTa. Brevo is working.', ['test']);
    echo $res['ok'] ? "Sent. Check the inbox and the Spam folder.\n" : 'Failed: ' . $res['error'] . "\n";
} elseif ($cmd === 'receipt' && $arg !== '') {
    ReceiptMailer::sendForRide($arg);
    echo "Done. Look at the email_log table for the result.\n";
} elseif ($cmd === 'preview') {
    $mail = null;
    if ($arg === 'otp' || $arg === 'reset') {
        $mail = MailTemplates::otp('123456', 10, $arg === 'reset' ? 'reset' : 'register');
    } elseif ($arg === 'changed') {
        $mail = MailTemplates::passwordChanged('Kael', date('M j, Y, g:i A'));
    } elseif ($arg === 'receipt' && ($argv[3] ?? '') !== '') {
        $t = ReceiptMailer::loadData($argv[3]);
        if ($t === null) {
            echo "No receipt found for that ride id.\n";
            exit(1);
        }
        $mail = MailTemplates::receipt($t, ($argv[4] ?? 'passenger') === 'driver' ? 'driver' : 'passenger');
    }
    if ($mail === null) {
        echo "Usage: preview otp | reset | changed | receipt <rideId> [passenger|driver]\n";
        exit(1);
    }
    file_put_contents(__DIR__ . '/preview.html', $mail['html']);
    echo "Saved php\\bin\\preview.html. Open it in your browser. Subject: " . $mail['subject'] . "\n";
} else {
    echo "Usage:\n  test <email>\n  receipt <rideId>\n  preview otp|reset|changed\n  preview receipt <rideId> [passenger|driver]\n";
}