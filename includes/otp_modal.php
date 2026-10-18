<?php

declare(strict_types=1);

function renderOtpModal(array $options): void
{
    static $stylesPrinted = false;

    $id = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)($options['id'] ?? 'otp'));
    $open = !empty($options['open']);
    $title = (string)($options['title'] ?? 'Verify your email');
    $subtitle = (string)($options['subtitle'] ?? 'Enter the 6-digit code we sent to:');
    $maskedEmail = (string)($options['masked_email'] ?? '');
    $csrfToken = (string)($options['csrf_token'] ?? '');
    $verifyAction = (string)($options['verify_action'] ?? 'verify_otp');
    $resendAction = (string)($options['resend_action'] ?? 'resend_otp');
    $cancelAction = (string)($options['cancel_action'] ?? 'cancel_otp');
    $actionUrl = (string)($options['action_url'] ?? '');
    $error = (string)($options['error'] ?? '');
    $success = (string)($options['success'] ?? '');
    $resendSeconds = max(0, (int)($options['resend_seconds'] ?? 0));
    $verifyLabel = (string)($options['verify_label'] ?? 'Verify');
    $cancelLabel = (string)($options['cancel_label'] ?? 'Change email');
    $expiryText = (string)($options['expiry_text'] ?? 'The verification code expires in 10 minutes.');

    if (!$stylesPrinted) {
        $stylesPrinted = true;
        echo <<<'HTML'
<style>
.otp-modal{position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(43,30,18,.48);backdrop-filter:blur(4px)}
.otp-modal.open{display:flex}.otp-card{width:min(470px,100%);border:1px solid #E7CDB0;border-radius:24px;background:#FFFCF7;box-shadow:0 28px 70px rgba(45,30,16,.24);padding:28px}.otp-icon{width:58px;height:58px;display:grid;place-items:center;margin:0 auto 14px;border-radius:18px;background:#F9EFE2;color:#A55325;font-size:1.45rem;font-weight:900}.otp-card h2{margin:0;color:#8b5423;text-align:center;font-size:1.55rem}.otp-subtitle{margin:8px auto 0;max-width:360px;color:#6f5a47;text-align:center;font-size:.9rem;line-height:1.55}.otp-email{margin-top:8px;color:#3f2e1f;text-align:center;font-size:.9rem;font-weight:800;overflow-wrap:anywhere}.otp-message{margin-top:16px;padding:10px 12px;border-radius:12px;font-size:.82rem;line-height:1.45;text-align:center}.otp-message.error{border:1px solid #efc0c0;background:#fff2f2;color:#a83d3d}.otp-message.success{border:1px solid #bfe2c9;background:#f2fbf5;color:#2f7a4d}.otp-inputs{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px;margin-top:22px}.otp-digit{width:100%;height:54px;border:1.5px solid #E7CDB0;border-radius:13px;background:#F9EFE2;color:#2B1E12;text-align:center;font:800 1.28rem/1 Inter,system-ui,sans-serif;outline:none}.otp-digit:focus{border-color:#B85C28;background:#FFFAF4;box-shadow:0 0 0 3px rgba(184,92,40,.1)}.otp-primary{width:100%;margin-top:18px;border:0;border-radius:60px;background:#B85C28;color:#fff;padding:14px 18px;font:inherit;font-size:.96rem;font-weight:800;cursor:pointer;box-shadow:0 6px 14px rgba(160,80,35,.26)}.otp-primary:hover{background:#94481F}.otp-primary:disabled{opacity:.65;cursor:not-allowed}.otp-actions{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:14px}.otp-actions form{margin:0}.otp-text-button{border:0;background:transparent;color:#B85C28;padding:5px;font:inherit;font-size:.82rem;font-weight:800;cursor:pointer}.otp-text-button:disabled{color:#a89483;cursor:not-allowed}.otp-separator{color:#c8b7a8;font-size:.8rem}.otp-expiry{margin-top:12px;color:#927b68;text-align:center;font-size:.76rem}@media(max-width:480px){.otp-card{padding:24px 18px}.otp-inputs{gap:6px}.otp-digit{height:48px;border-radius:10px;font-size:1.1rem}}
</style>
HTML;
    }

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="otp-modal<?= $open ? ' open' : '' ?>" id="<?= $e($id) ?>-modal" aria-hidden="<?= $open ? 'false' : 'true' ?>">
        <div class="otp-card" role="dialog" aria-modal="true" aria-labelledby="<?= $e($id) ?>-title">
            <div class="otp-icon" aria-hidden="true">@</div>
            <h2 id="<?= $e($id) ?>-title"><?= $e($title) ?></h2>
            <p class="otp-subtitle"><?= $e($subtitle) ?></p>
            <div class="otp-email"><?= $e($maskedEmail) ?></div>

            <?php if ($error !== ''): ?>
                <div class="otp-message error"><?= $e($error) ?></div>
            <?php elseif ($success !== ''): ?>
                <div class="otp-message success"><?= $e($success) ?></div>
            <?php endif; ?>

            <form action="<?= $e($actionUrl) ?>" method="POST" id="<?= $e($id) ?>-verify-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= $e($verifyAction) ?>">
                <input type="hidden" name="otp" id="<?= $e($id) ?>-value" value="">

                <div class="otp-inputs" id="<?= $e($id) ?>-inputs" aria-label="6-digit verification code">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" <?= $i === 1 ? 'autocomplete="one-time-code"' : '' ?> aria-label="Digit <?= $i ?>">
                    <?php endfor; ?>
                </div>

                <button class="otp-primary" id="<?= $e($id) ?>-verify-button" type="submit"><?= $e($verifyLabel) ?></button>
            </form>

            <div class="otp-actions">
                <form action="<?= $e($actionUrl) ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $e($resendAction) ?>">
                    <button class="otp-text-button" id="<?= $e($id) ?>-resend-button" type="submit">Resend code</button>
                </form>

                <span class="otp-separator">•</span>

                <form action="<?= $e($actionUrl) ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $e($cancelAction) ?>">
                    <button class="otp-text-button" type="submit"><?= $e($cancelLabel) ?></button>
                </form>
            </div>

            <div class="otp-expiry"><?= $e($expiryText) ?></div>
        </div>
    </div>

    <script>
    (() => {
        const prefix = <?= json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const modal = document.getElementById(prefix + '-modal');
        const verifyForm = document.getElementById(prefix + '-verify-form');
        const hiddenValue = document.getElementById(prefix + '-value');
        const digits = Array.from(modal?.querySelectorAll('.otp-digit') || []);
        const verifyButton = document.getElementById(prefix + '-verify-button');
        const resendButton = document.getElementById(prefix + '-resend-button');
        let resendSeconds = <?= (int)$resendSeconds ?>;

        const sync = () => {
            const value = digits.map(input => input.value).join('');
            if (hiddenValue) hiddenValue.value = value;
            return value;
        };

        if (modal?.classList.contains('open')) {
            document.body.style.overflow = 'hidden';
            window.setTimeout(() => digits[0]?.focus(), 80);
        }

        digits.forEach((input, index) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 1);
                sync();
                if (input.value && digits[index + 1]) digits[index + 1].focus();
            });

            input.addEventListener('keydown', event => {
                if (event.key === 'Backspace' && !input.value && digits[index - 1]) digits[index - 1].focus();
                if (event.key === 'ArrowLeft' && digits[index - 1]) { event.preventDefault(); digits[index - 1].focus(); }
                if (event.key === 'ArrowRight' && digits[index + 1]) { event.preventDefault(); digits[index + 1].focus(); }
            });

            input.addEventListener('paste', event => {
                const pasted = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6);
                if (!pasted) return;
                event.preventDefault();
                pasted.split('').forEach((digit, i) => { if (digits[i]) digits[i].value = digit; });
                sync();
                digits[Math.min(pasted.length, 6) - 1]?.focus();
            });
        });

        verifyForm?.addEventListener('submit', event => {
            const code = sync();
            if (!/^\d{6}$/.test(code)) {
                event.preventDefault();
                digits.find(input => !input.value)?.focus();
                return;
            }
            if (verifyButton) {
                verifyButton.disabled = true;
                verifyButton.textContent = 'Verifying...';
            }
        });

        const updateResend = () => {
            if (!resendButton) return;
            if (resendSeconds > 0) {
                resendButton.disabled = true;
                resendButton.textContent = `Resend code (${resendSeconds}s)`;
                resendSeconds--;
                window.setTimeout(updateResend, 1000);
            } else {
                resendButton.disabled = false;
                resendButton.textContent = 'Resend code';
            }
        };

        updateResend();
    })();
    </script>
    <?php
}
