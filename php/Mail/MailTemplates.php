<?php declare(strict_types=1);

namespace Sakayta\Mail;

/**
 * The emails we send. The layout uses tables and inline styles, because email apps (Gmail, Outlook)
 * ignore most CSS. Everything that comes from a user (names, places) goes through e() first.
 */
final class MailTemplates
{
    private const GREEN = '#2F7D55';
    private const INK = '#14201A';
    private const MUTED = '#606770';
    private const FONT = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The logo: a public https picture from MAIL_LOGO_URL, or a small badge when none is set. */
    private static function logo(): string
    {
        $url = Env::get('MAIL_LOGO_URL');
        if ($url !== '' && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return '<img src="' . self::e($url) . '" width="46" height="46" alt="SakayTa" '
                . 'style="display:block;width:46px;height:46px;border-radius:50%;border:0">';
        }
        return '<div style="width:46px;height:46px;border-radius:50%;background:#1d4a36;overflow:hidden">'
        . '<img src="' . self::e($url) . '" width="46" height="46" alt="" '
        . 'style="display:block;width:46px;height:46px;border:0;border-radius:50%"></div>';
    }

    private static function header(string $title, string $subtitle): string
    {
        return '<tr><td style="background:' . self::GREEN . ';background-image:linear-gradient(135deg,#1f4d38,#3f9a68);padding:22px 24px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td valign="middle" style="padding-right:12px">' . self::logo() . '</td>'
            . '<td valign="middle" style="font-family:' . self::FONT . ';font-size:20px;font-weight:700;color:#ffffff">SakayTa</td>'
            . '</tr></table>'
            . '<div style="margin-top:18px;font-family:' . self::FONT . ';font-size:24px;font-weight:800;line-height:1.25;color:#ffffff">' . self::e($title) . '</div>'
            . '<div style="margin-top:4px;font-family:' . self::FONT . ';font-size:14px;color:#d9eee3">' . self::e($subtitle) . '</div>'
            . '</td></tr>';
    }

    /** $footerHtml must only hold text we wrote ourselves. */
    private static function shell(string $header, string $body, string $footerHtml): string
    {
        return '<div style="background:#f0f2f5;padding:24px 12px;font-family:' . self::FONT . ';color:' . self::INK . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden">'
            . $header
            . '<tr><td style="padding:24px;font-family:' . self::FONT . '">' . $body . '</td></tr>'
            . '<tr><td style="padding:0 24px 22px;font-family:' . self::FONT . ';font-size:12px;line-height:1.5;color:' . self::MUTED . '">' . $footerHtml . '</td></tr>'
            . '</table></div>';
    }

    private static function lbl(string $s): string
    {
        return '<div style="font-size:11px;font-weight:700;letter-spacing:1px;color:' . self::MUTED . '">' . self::e(strtoupper($s)) . '</div>';
    }

    private static function cell(string $label, string $value): string
    {
        return '<td width="50%" valign="top" style="padding:0 8px 14px 0">' . self::lbl($label)
            . '<div style="margin-top:2px;font-size:14px;font-weight:700;word-wrap:break-word;overflow-wrap:anywhere">' . self::e($value) . '</div></td>';
    }

    private static function place(mixed $name, mixed $lat, mixed $lng): string
    {
        $n = is_string($name) ? trim($name) : '';
        if ($n !== '') {
            return $n;
        }
        return is_numeric($lat) && is_numeric($lng)
            ? number_format((float) $lat, 5) . ', ' . number_format((float) $lng, 5)
            : '';
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function otp(string $code, int $minutes, string $purpose = 'register'): array
    {
        $reset = $purpose === 'reset';
        $title = $reset ? 'Reset your password' : 'Verify your email';
        $intro = $reset ? 'Use this code to reset your SakayTa password:' : 'Use this code to finish creating your SakayTa account:';
        $ignore = $reset
            ? 'If you did not ask to reset your password, you can ignore this email. Your password will stay the same.'
            : 'If you did not try to sign up, you can ignore this email.';

        $body = '<p style="margin:0 0 14px;font-size:16px;line-height:1.5">' . self::e($intro) . '</p>'
            . '<div style="margin:0 0 16px;padding:16px 10px 16px 20px;text-align:center;background:#f7f8fa;border:2px dashed #b9d9c7;border-radius:14px;'
            . 'font-size:36px;font-weight:800;letter-spacing:10px;color:' . self::GREEN . '">' . self::e($code) . '</div>'
            . '<p style="margin:0 0 8px;font-size:14px;color:' . self::MUTED . '">This code works for ' . $minutes . ' minutes and can be used once.</p>'
            . '<p style="margin:0;font-size:14px;color:' . self::MUTED . '"><strong>Never share this code.</strong> SakayTa will never ask you for it.</p>';

        return [
            'subject' => ($reset ? 'Your SakayTa password reset code: ' : 'Your SakayTa verification code: ') . $code,
            'html'    => self::shell(self::header($title, 'SakayTa account'), $body, self::e($ignore)),
            'text'    => $title . "\n\n" . $intro . "\n{$code}\n\nThis code works for {$minutes} minutes and can be used once.\nNever share this code. SakayTa will never ask you for it.\n" . $ignore . "\n",
        ];
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function passwordChanged(string $name, string $when): array
    {
        $hi = trim($name) !== '' ? 'Hi ' . $name . ',' : 'Hi,';
        $body = '<p style="margin:0 0 12px;font-size:16px;line-height:1.5">' . self::e($hi) . '</p>'
            . '<p style="margin:0 0 12px;font-size:16px;line-height:1.5">The password of your SakayTa account was changed on <strong>' . self::e($when) . '</strong>.</p>'
            . '<p style="margin:0;font-size:14px;line-height:1.5;color:' . self::MUTED . '">If this was you, you do not need to do anything. '
            . 'If it was not you, use <strong>Forgot password</strong> on the log in page right away to set a new password.</p>';

        return [
            'subject' => 'Your SakayTa password was changed',
            'html'    => self::shell(self::header('Password changed', 'SakayTa account'), $body, 'This is an automatic message. Please do not reply.'),
            'text'    => $hi . "\n\nThe password of your SakayTa account was changed on {$when}.\nIf this was not you, use Forgot password on the log in page right away.\n",
        ];
    }

    /** @param array<string,mixed> $t  @return array{subject:string,html:string,text:string} */
    public static function receipt(array $t, string $role): array
    {
        $isDriver = $role === 'driver';
        $fare = '₱' . number_format((float) ($t['fareAmount'] ?? 0), 2);
        $when = !empty($t['completedAt']) ? date('M j, Y · g:i A', (int) strtotime((string) $t['completedAt'])) : '';
        $type = in_array((string) ($t['rideType'] ?? ''), ['', 'Tricycle'], true) ? 'Taxicle' : (string) $t['rideType'];
        $pay = strtolower((string) ($t['paymentMethod'] ?? 'cash')) === 'cash' ? 'Cash' : (string) $t['paymentMethod'];
        $km = is_numeric($t['distanceKm'] ?? null) ? number_format((float) $t['distanceKm'], 2) . ' km' : '';
        $pickup = self::place($t['pickupAddress'] ?? null, $t['pickupLat'] ?? null, $t['pickupLng'] ?? null);
        $dropoff = self::place($t['dropoffAddress'] ?? null, $t['dropoffLat'] ?? null, $t['dropoffLng'] ?? null);
        $passenger = (string) ($t['passengerName'] ?? '');
        $driver = (string) ($t['driverName'] ?? '');
        $rideRef = (string) ($t['rideId'] ?? '');
        $txRef = (string) ($t['txId'] ?? '');

        // total and date
        $top = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td valign="bottom">' . self::lbl('Total fare')
            . '<div style="font-size:34px;font-weight:800;line-height:1.1;color:' . self::GREEN . '">' . self::e($fare) . '</div></td>'
            . ($when !== ''
                ? '<td valign="bottom" align="right">' . self::lbl('Completed')
                  . '<div style="margin-top:2px;font-size:13px;font-weight:700">' . self::e($when) . '</div></td>'
                : '')
            . '</tr></table>';

        // trip: pickup and drop-off with two dots
        $pickupDot = '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . self::GREEN . '"></span>';
        $dropDot = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;border:3px solid ' . self::INK . ';background:#ffffff"></span>';
        $stop = static function (string $dot, string $label, string $name): string {
            return '<tr><td width="26" valign="top" style="padding:3px 0 14px">' . $dot . '</td>'
                . '<td valign="top" style="padding:0 0 14px">' . self::lbl($label)
                . '<div style="margin-top:2px;font-size:16px;font-weight:700;line-height:1.3;word-wrap:break-word;overflow-wrap:anywhere">'
                . self::e($name !== '' ? $name : '—') . '</div></td></tr>';
        };
        $trip = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;background:#f7f8fa;border-radius:12px">'
            . '<tr><td style="padding:16px 16px 2px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . $stop($pickupDot, 'Pickup', $pickup) . $stop($dropDot, 'Drop-off', $dropoff)
            . '</table></td></tr></table>';

        // details
        $grid = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px"><tr>'
            . self::cell('Ride type', $type) . self::cell('Distance', $km !== '' ? $km : '—')
            . '</tr><tr>'
            . self::cell('Passenger', $passenger !== '' ? $passenger : '—') . self::cell('Driver', $driver !== '' ? $driver : '—')
            . '</tr></table>';

        // receipt summary
        $row = static function (string $label, string $valueHtml, bool $line = false, bool $strong = false): string {
            $b = $line ? 'border-top:1px dashed #dddfe2;' : '';
            return '<tr><td style="' . $b . 'padding:10px 0;font-size:' . ($strong ? '16' : '14') . 'px;font-weight:' . ($strong ? '800' : '400') . ';color:' . ($strong ? self::INK : self::MUTED) . '">' . self::e($label) . '</td>'
                . '<td align="right" style="' . $b . 'padding:10px 0;font-size:' . ($strong ? '20' : '14') . 'px;font-weight:' . ($strong ? '800' : '700') . ';color:' . ($strong ? self::GREEN : self::INK) . '">' . $valueHtml . '</td></tr>';
        };
        $pill = '<span style="display:inline-block;padding:3px 14px;border-radius:999px;background:#e6f2ec;color:' . self::GREEN . ';font-size:13px;font-weight:700">' . self::e($pay) . '</span>';
        $sum = '<div style="margin:6px 0 0;font-size:11px;font-weight:700;letter-spacing:1px;color:' . self::MUTED . '">RECEIPT SUMMARY</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;border:1px solid #dddfe2;border-radius:12px"><tr><td style="padding:2px 16px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . $row('Payment method', $pill)
            . $row('Ride fare', self::e($fare), true)
            . $row('Total', self::e($fare), true, true)
            . '</table></td></tr></table>'
            . '<p style="margin:10px 0 0;font-size:12px;line-height:1.5;color:' . self::MUTED . '">The payment method is recorded for reference. This receipt does not confirm that cash was collected.</p>';

        // references (they wrap, so a long number is never cut off)
        $refs = '<div style="margin-top:20px;padding-top:14px;border-top:1px solid #eef0f2">' . self::lbl('References');
        foreach ([['Ride reference', $rideRef], ['Transaction reference', $txRef]] as [$l, $v]) {
            if ($v !== '') {
                $refs .= '<div style="margin-top:10px">' . self::lbl($l)
                    . '<div style="margin-top:2px;font-size:12px;word-break:break-all;color:' . self::INK . '">' . self::e($v) . '</div></div>';
            }
        }
        $refs .= '</div>';

        $title = $isDriver ? 'Trip completed' : 'Hope you had a safe ride!';
        $subtitle = $isDriver ? 'Your completed trip receipt' : 'Your completed ride receipt';
        $html = self::shell(
            self::header($title, $subtitle),
            $top . $trip . $grid . $sum . $refs,
            'Place names &copy; OpenStreetMap contributors.<br>This is an automatic message. Please do not reply.'
        );

        $text = $title . "\n" . $subtitle . "\n\nTotal fare: {$fare}\n" . ($when !== '' ? "Completed: {$when}\n" : '')
            . "\nPickup: " . ($pickup !== '' ? $pickup : '-') . "\nDrop-off: " . ($dropoff !== '' ? $dropoff : '-')
            . "\nRide type: {$type}\n" . ($km !== '' ? "Distance: {$km}\n" : '')
            . "Passenger: {$passenger}\nDriver: {$driver}\nPayment: {$pay}\n\n"
            . "Ride reference: {$rideRef}\nTransaction reference: {$txRef}\n\n"
            . "The payment method is recorded for reference. This receipt does not confirm that cash was collected.\n";

        return [
            'subject' => ($isDriver ? 'SakayTa trip receipt: ' : 'Your SakayTa receipt: ') . $fare,
            'html'    => $html,
            'text'    => $text,
        ];
    }
}