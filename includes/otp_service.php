<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function otpMailConfig(): array
{
    $path = dirname(__DIR__) . '/config/mail_config.php';

    if (!is_file($path)) {
        return [];
    }

    $config = require $path;

    return is_array($config) ? $config : [];
}

function otpMaskEmail(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

    if ($domain === '') {
        return $email;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($local) : strlen($local);
    $visibleLength = min(2, $length);
    $visible = function_exists('mb_substr')
        ? mb_substr($local, 0, $visibleLength)
        : substr($local, 0, $visibleLength);

    return $visible . str_repeat('•', max(3, $length - $visibleLength)) . '@' . $domain;
}

function otpGenerateCode(): string
{
    return (string)random_int(100000, 999999);
}

function otpSessionKey(string $flowKey): string
{
    return 'otp_challenge_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $flowKey);
}

function otpSendEmail(
    string $recipientEmail,
    string $recipientName,
    string $otp,
    string $purposeText = 'complete your request'
): void {
    $config = otpMailConfig();
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';

    if (empty($config['use_smtp']) || !is_file($autoload)) {
        throw new RuntimeException('SMTP is not ready. Please check PHPMailer and mail_config.php.');
    }

    require_once $autoload;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

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

        if ($mail->Host === '' || $mail->Username === '' || $mail->Password === '' || $fromEmail === '') {
            throw new RuntimeException('SMTP email settings are incomplete.');
        }

        $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $safePurpose = htmlspecialchars($purposeText, ENT_QUOTES, 'UTF-8');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = 'Your LokalCraft verification code';
        $mail->Body = '
            <div style="font-family:Arial,sans-serif;background:#fff8ef;padding:28px;color:#3f2e1f;">
                <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e7cdb0;border-radius:18px;padding:28px;">
                    <div style="font-size:24px;font-weight:800;color:#a55325;margin-bottom:18px;">LokalCraft</div>
                    <p style="margin:0 0 12px;">Hello ' . $safeName . ',</p>
                    <p style="margin:0 0 18px;line-height:1.6;">Use this verification code to ' . $safePurpose . '.</p>
                    <div style="font-size:32px;letter-spacing:8px;font-weight:800;text-align:center;color:#8b5423;background:#fff5e9;border:1px solid #efcaa5;border-radius:12px;padding:18px;margin:18px 0;">' . $safeOtp . '</div>
                    <p style="margin:0;line-height:1.6;color:#6f5a47;">This code expires in 10 minutes. If you did not request this code, you can ignore this email.</p>
                </div>
            </div>';
        $mail->AltBody = "Hello {$recipientName},\n\nYour LokalCraft verification code is: {$otp}\n\nThis code expires in 10 minutes.";
        $mail->send();
    } catch (Throwable $e) {
        error_log('LokalCraft OTP SMTP error: ' . $e->getMessage());
        throw new RuntimeException('Unable to send the verification code. Please check the email service configuration.');
    }
}

function otpStartChallenge(
    string $flowKey,
    string $email,
    string $recipientName,
    array $payload = [],
    string $purposeText = 'complete your request',
    int $ttlSeconds = 600,
    int $resendCooldownSeconds = 60
): void {
    $otp = otpGenerateCode();

    otpSendEmail($email, $recipientName, $otp, $purposeText);

    $_SESSION[otpSessionKey($flowKey)] = [
        'email' => $email,
        'recipient_name' => $recipientName,
        'payload' => $payload,
        'purpose_text' => $purposeText,
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + $ttlSeconds,
        'resend_after' => time() + $resendCooldownSeconds,
        'attempts' => 0,
        'max_attempts' => 5,
        'ttl_seconds' => $ttlSeconds,
        'resend_cooldown' => $resendCooldownSeconds,
    ];
}

function otpGetChallenge(string $flowKey): ?array
{
    $value = $_SESSION[otpSessionKey($flowKey)] ?? null;
    return is_array($value) ? $value : null;
}

function otpClearChallenge(string $flowKey): void
{
    unset($_SESSION[otpSessionKey($flowKey)]);
}

function otpResendSeconds(string $flowKey): int
{
    $challenge = otpGetChallenge($flowKey);

    if (!$challenge) {
        return 0;
    }

    return max(0, (int)($challenge['resend_after'] ?? 0) - time());
}

function otpResendChallenge(string $flowKey): array
{
    $challenge = otpGetChallenge($flowKey);

    if (!$challenge) {
        return ['success' => false, 'message' => 'Your verification session was not found. Please start again.'];
    }

    $wait = otpResendSeconds($flowKey);
    if ($wait > 0) {
        return [
            'success' => false,
            'message' => "Please wait {$wait} second" . ($wait === 1 ? '' : 's') . ' before requesting another code.',
        ];
    }

    $otp = otpGenerateCode();

    try {
        otpSendEmail(
            (string)$challenge['email'],
            (string)$challenge['recipient_name'],
            $otp,
            (string)($challenge['purpose_text'] ?? 'complete your request')
        );
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    $challenge['otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
    $challenge['expires_at'] = time() + (int)($challenge['ttl_seconds'] ?? 600);
    $challenge['resend_after'] = time() + (int)($challenge['resend_cooldown'] ?? 60);
    $challenge['attempts'] = 0;

    $_SESSION[otpSessionKey($flowKey)] = $challenge;

    return ['success' => true, 'message' => 'A new verification code was sent to your email.'];
}

function otpVerifyChallenge(string $flowKey, string $inputCode): array
{
    $challenge = otpGetChallenge($flowKey);

    if (!$challenge) {
        return ['success' => false, 'message' => 'Your verification session was not found. Please start again.'];
    }

    $otp = preg_replace('/\D+/', '', $inputCode);

    if ((int)($challenge['expires_at'] ?? 0) < time()) {
        return ['success' => false, 'message' => 'The verification code has expired. Request a new code.'];
    }

    if (strlen($otp) !== 6) {
        return ['success' => false, 'message' => 'Please enter the complete 6-digit verification code.'];
    }

    $maxAttempts = (int)($challenge['max_attempts'] ?? 5);
    $attempts = (int)($challenge['attempts'] ?? 0);

    if ($attempts >= $maxAttempts) {
        otpClearChallenge($flowKey);
        return ['success' => false, 'message' => 'Too many incorrect attempts. Please start again.'];
    }

    if (!password_verify($otp, (string)($challenge['otp_hash'] ?? ''))) {
        $attempts++;
        $challenge['attempts'] = $attempts;
        $_SESSION[otpSessionKey($flowKey)] = $challenge;

        $remaining = max(0, $maxAttempts - $attempts);

        if ($remaining === 0) {
            otpClearChallenge($flowKey);
            return ['success' => false, 'message' => 'Too many incorrect attempts. Please start again.'];
        }

        return [
            'success' => false,
            'message' => "Incorrect verification code. {$remaining} attempt" . ($remaining === 1 ? '' : 's') . ' remaining.',
        ];
    }

    return [
        'success' => true,
        'message' => 'Email verified successfully.',
        'email' => (string)$challenge['email'],
        'payload' => is_array($challenge['payload'] ?? null) ? $challenge['payload'] : [],
    ];
}
