<?php

declare(strict_types=1);

/**
 * LokalCraft seller-application email notifications.
 *
 * This service is intentionally separate from otp_service.php.
 * It does not create OTPs, touch OTP sessions, resend timers,
 * verification attempts, or any OTP-related state.
 */

function sellerNotificationMailConfig(): array
{
    $path = dirname(__DIR__) . '/config/mail_config.php';

    if (!is_file($path)) {
        return [];
    }

    $config = require $path;

    return is_array($config) ? $config : [];
}

function sellerNotificationSendDecisionEmail(
    string $recipientEmail,
    string $recipientName,
    string $businessName,
    string $status,
    string $rejectionReason = ''
): void {
    $recipientEmail = trim($recipientEmail);
    $recipientName = trim($recipientName);
    $businessName = trim($businessName);
    $status = strtolower(trim($status));
    $rejectionReason = trim($rejectionReason);

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The seller email address is invalid.');
    }

    if (!in_array($status, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Unsupported seller application status.');
    }

    if ($status === 'rejected' && $rejectionReason === '') {
        throw new InvalidArgumentException('A rejection reason is required for rejection emails.');
    }

    $config = sellerNotificationMailConfig();
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';

    if (empty($config['use_smtp']) || !is_file($autoload)) {
        throw new RuntimeException(
            'SMTP is not ready. Please check PHPMailer and config/mail_config.php.'
        );
    }

    require_once $autoload;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $safeName = htmlspecialchars(
        $recipientName !== '' ? $recipientName : 'LokalCraft User',
        ENT_QUOTES,
        'UTF-8'
    );

    $safeBusiness = htmlspecialchars(
        $businessName !== '' ? $businessName : 'your business',
        ENT_QUOTES,
        'UTF-8'
    );

    $safeReason = htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8');

    if ($status === 'approved') {
        $subject = 'LokalCraft Seller Application Approved';

        $headline = 'Your seller application has been approved';
        $statusLabel = 'Approved';
        $statusBackground = '#eaf7ef';
        $statusColor = '#2f7a4d';
        $message = '
            <p style="margin:0 0 14px;line-height:1.65;">
                Good news! Your seller application for
                <strong>' . $safeBusiness . '</strong> has been approved.
            </p>
            <p style="margin:0;line-height:1.65;">
                Your LokalCraft account now has seller access. You may log in to your
                account and open the Seller Interface to manage your business.
            </p>
        ';

        $plainBody =
            "Hello " . ($recipientName !== '' ? $recipientName : 'LokalCraft User') . ",\n\n"
            . "Good news! Your seller application for "
            . ($businessName !== '' ? $businessName : 'your business')
            . " has been approved.\n\n"
            . "Your LokalCraft account now has seller access. "
            . "You may log in to your account and open the Seller Interface.\n\n"
            . "Thank you,\nLokalCraft";
    } else {
        $subject = 'LokalCraft Seller Application Update';

        $headline = 'Your seller application needs an update';
        $statusLabel = 'Rejected';
        $statusBackground = '#fff0f1';
        $statusColor = '#b33a45';
        $message = '
            <p style="margin:0 0 14px;line-height:1.65;">
                Your seller application for
                <strong>' . $safeBusiness . '</strong>
                was not approved at this time.
            </p>
            <div style="
                margin:16px 0;
                padding:14px 16px;
                border:1px solid #efc3c7;
                border-radius:12px;
                background:#fff7f7;
            ">
                <div style="
                    margin-bottom:6px;
                    color:#8a4b52;
                    font-size:12px;
                    font-weight:800;
                    text-transform:uppercase;
                    letter-spacing:.4px;
                ">
                    Reason
                </div>
                <div style="color:#5b3a3e;line-height:1.6;">' . nl2br($safeReason) . '</div>
            </div>
            <p style="margin:0;line-height:1.65;">
                You may log in to your Buyer account, review the reason, update the
                required information or documents, and submit your seller application again.
            </p>
        ';

        $plainBody =
            "Hello " . ($recipientName !== '' ? $recipientName : 'LokalCraft User') . ",\n\n"
            . "Your seller application for "
            . ($businessName !== '' ? $businessName : 'your business')
            . " was not approved at this time.\n\n"
            . "Reason:\n" . $rejectionReason . "\n\n"
            . "You may log in to your Buyer account, update the required information "
            . "or documents, and submit your seller application again.\n\n"
            . "Thank you,\nLokalCraft";
    }

    $htmlBody = '
        <div style="
            margin:0;
            padding:30px 16px;
            background:#fff8ef;
            color:#3f2e1f;
            font-family:Arial,sans-serif;
        ">
            <div style="
                max-width:560px;
                margin:0 auto;
                overflow:hidden;
                border:1px solid #e7cdb0;
                border-radius:18px;
                background:#ffffff;
            ">
                <div style="padding:26px 28px 18px;">
                    <div style="
                        margin-bottom:18px;
                        color:#a55325;
                        font-size:24px;
                        font-weight:800;
                    ">
                        LokalCraft
                    </div>

                    <div style="
                        display:inline-block;
                        margin-bottom:16px;
                        padding:7px 11px;
                        border-radius:999px;
                        background:' . $statusBackground . ';
                        color:' . $statusColor . ';
                        font-size:12px;
                        font-weight:800;
                    ">
                        ' . $statusLabel . '
                    </div>

                    <h1 style="
                        margin:0 0 16px;
                        color:#3b3027;
                        font-size:22px;
                        line-height:1.25;
                    ">
                        ' . $headline . '
                    </h1>

                    <p style="margin:0 0 14px;line-height:1.65;">
                        Hello ' . $safeName . ',
                    </p>

                    ' . $message . '
                </div>

                <div style="
                    padding:16px 28px 22px;
                    border-top:1px solid #f0dfce;
                    background:#fffdf9;
                    color:#7a6858;
                    font-size:12px;
                    line-height:1.55;
                ">
                    This is an automated notification from LokalCraft.
                </div>
            </div>
        </div>
    ';

    try {
        $mail->isSMTP();
        $mail->Host = (string)($config['host'] ?? '');
        $mail->SMTPAuth = true;
        $mail->Username = (string)($config['username'] ?? '');
        $mail->Password = (string)($config['password'] ?? '');
        $mail->Port = (int)($config['port'] ?? 587);

        $encryption = strtolower((string)($config['encryption'] ?? 'tls'));
        $mail->SMTPSecure = $encryption === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = (string)($config['from_email'] ?? $config['username'] ?? '');
        $fromName = (string)($config['from_name'] ?? 'LokalCraft');

        if (
            $mail->Host === ''
            || $mail->Username === ''
            || $mail->Password === ''
            || $fromEmail === ''
        ) {
            throw new RuntimeException('SMTP email settings are incomplete.');
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress(
            $recipientEmail,
            $recipientName !== '' ? $recipientName : $recipientEmail
        );

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();
    } catch (Throwable $e) {
        error_log('LokalCraft seller notification SMTP error: ' . $e->getMessage());

        throw new RuntimeException(
            'Unable to send the seller application email notification.'
        );
    }
}
