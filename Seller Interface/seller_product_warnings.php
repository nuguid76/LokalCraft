<?php
declare(strict_types=1);
/**
 * Seller-only moderation popup.
 * Include once after authentication on a Seller Interface page.
 * Product removal notices are stored as admin-to-self messages by product_listing.php,
 * so the same account's Buyer chat will not receive these moderation records.
 */
require_once __DIR__ . '/seller_auth.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException('Seller database connection unavailable.');
}
$warningSellerId = (int)($sellerId ?? 0);
if ($warningSellerId <= 0) {
    throw new RuntimeException('Seller account unavailable.');
}
if (empty($_SESSION['seller_warning_csrf'])) {
    $_SESSION['seller_warning_csrf'] = bin2hex(random_bytes(32));
}
$warningCsrf = (string)$_SESSION['seller_warning_csrf'];
$warningPattern = '[LokalCraft Seller Notice] Seller #' . $warningSellerId . ' Product #%';

// An acknowledgement is sent directly to this file, never to the dashboard handler.
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['warning_action'] ?? '') === 'acknowledge'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($warningCsrf, $postedCsrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Session expired. Refresh the page.']);
            exit;
        }
        $ids = json_decode((string)($_POST['message_ids'] ?? ''), true);
        if (!is_array($ids) || !$ids || count($ids) > 12) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid notification selection.']);
            exit;
        }
        $cleanIds = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID.']);
                exit;
            }
            $cleanIds[$id] = $id;
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE messages m
                INNER JOIN users admin ON admin.id = m.sender_id
                SET m.is_read = 1
                WHERE m.message_id = ? AND m.is_read = 0
                  AND m.sender_id = m.receiver_id
                  AND admin.AdminOrNot = 1
                  AND m.message_text LIKE ?");
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare notification acknowledgement.');
            }
            foreach ($cleanIds as $messageId) {
                $stmt->bind_param('is', $messageId, $warningPattern);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('A warning was already read or is not available.');
                }
            }
            $stmt->close();
            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    } catch (Throwable $error) {
        error_log('LokalCraft warning acknowledgement: ' . $error->getMessage());
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Unable to save acknowledgement. Please refresh and try again.']);
    }
    exit;
}

$sellerWarnings = [];
try {
    $stmt = $conn->prepare("SELECT m.message_id, m.message_text, m.sent_at
        FROM messages m
        INNER JOIN users admin ON admin.id = m.sender_id
        WHERE m.sender_id = m.receiver_id
          AND admin.AdminOrNot = 1
          AND m.is_read = 0
          AND m.message_text LIKE ?
        ORDER BY m.sent_at ASC, m.message_id ASC
        LIMIT 12");
    if (!$stmt) {
        throw new RuntimeException('Unable to load moderation notices.');
    }
    $stmt->bind_param('s', $warningPattern);
    $stmt->execute();
    $stmt->bind_result($noticeId, $noticeText, $noticeTime);
    while ($stmt->fetch()) {
        if (!preg_match(
            '/^\[LokalCraft Seller Notice\] Seller #(\d+) Product #(\d+) removed from marketplace\. Reason: (.*)$/s',
            (string)$noticeText,
            $matches
        )) {
            continue;
        }
        if ((int)$matches[1] !== $warningSellerId) {
            continue;
        }
        $sellerWarnings[] = [
            'message_id' => (int)$noticeId,
            'product_id' => (int)$matches[2],
            'reason' => trim((string)$matches[3]),
            'created_at' => (string)$noticeTime,
            'product_name' => ''
        ];
    }
    $stmt->close();
    if ($sellerWarnings) {
        $nameStmt = $conn->prepare('SELECT product_name FROM products WHERE product_id = ? AND seller_id = ? LIMIT 1');
        if ($nameStmt) {
            foreach ($sellerWarnings as &$warning) {
                $nameStmt->bind_param('ii', $warning['product_id'], $warningSellerId);
                $nameStmt->execute();
                $nameStmt->bind_result($productName);
                if ($nameStmt->fetch()) {
                    $warning['product_name'] = trim((string)$productName);
                }
                $nameStmt->free_result();
            }
            unset($warning);
            $nameStmt->close();
        }
    }
} catch (Throwable $error) {
    error_log('LokalCraft seller warning popup: ' . $error->getMessage());
    $sellerWarnings = [];
}
if ($sellerWarnings):
    $warningIds = array_column($sellerWarnings, 'message_id');
    $warningJson = json_encode($warningIds, JSON_UNESCAPED_SLASHES);
    if (!is_string($warningJson)) {
        $warningJson = '[]';
    }
?>
<div class="lc-warning-overlay" id="lcSellerWarningOverlay" aria-hidden="false">
    <section class="lc-warning-popup" role="dialog" aria-modal="true" aria-labelledby="lcWarningTitle" aria-describedby="lcWarningIntro" tabindex="-1">
        <header class="lc-warning-header">
            <div class="lc-warning-heading">
                <span class="lc-warning-mark" aria-hidden="true">!</span>
                <div>
                    <h2 id="lcWarningTitle">Marketplace Warning</h2>
                    <p id="lcWarningIntro">An administrator removed <?= count($sellerWarnings) === 1 ? 'a product listing' : 'product listings' ?> from the marketplace.</p>
                </div>
            </div>
            <button class="lc-warning-close" id="lcWarningClose" type="button" aria-label="Close warning for now">&times;</button>
        </header>
        <div class="lc-warning-body">
            <?php foreach ($sellerWarnings as $warning): ?>
                <article class="lc-warning-item">
                    <div class="lc-warning-item-top">
                        <strong><?= htmlspecialchars($warning['product_name'] !== '' ? $warning['product_name'] : 'Product #' . $warning['product_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <span>Removed</span>
                    </div>
                    <p><b>Reason:</b> <?= nl2br(htmlspecialchars($warning['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                    <small><?= htmlspecialchars(($date = strtotime($warning['created_at'])) ? date('M j, Y · g:i A', $date) : 'Date unavailable', ENT_QUOTES, 'UTF-8') ?></small>
                </article>
            <?php endforeach; ?>
            <p class="lc-warning-hint">Archived products are no longer shown in the Buyer marketplace.</p>
            <p class="lc-warning-error" id="lcWarningError" role="alert" hidden></p>
        </div>
        <footer class="lc-warning-footer">
            <button type="button" class="lc-warning-later" id="lcWarningLater">Close for now</button>
            <button type="button" class="lc-warning-ack" id="lcWarningAcknowledge">I understand</button>
        </footer>
    </section>
</div>
<style>
.lc-warning-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(39,32,25,.37);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.lc-warning-popup{width:min(490px,100%);max-height:min(90dvh,740px);overflow:hidden;display:flex;flex-direction:column;border:1px solid #e9ded1;border-radius:18px;background:#fff;box-shadow:0 18px 55px rgba(40,30,20,.18);font-family:"Segoe UI",Arial,sans-serif;color:#473e36}
.lc-warning-header{flex:none;display:flex;justify-content:space-between;gap:12px;padding:20px 20px 16px;border-bottom:1px solid #eee9e4}
.lc-warning-heading{display:flex;align-items:flex-start;gap:12px;min-width:0}
.lc-warning-mark{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:11px;background:#fff0df;color:#b36a2b;font-size:1rem;font-weight:800}
.lc-warning-heading h2{margin:0 0 5px;font-size:1.04rem;font-weight:750;line-height:1.3;color:#493b31}
.lc-warning-heading p{margin:0;color:#83796e;font-size:.79rem;line-height:1.5}
.lc-warning-close{flex:none;width:29px;height:29px;display:grid;place-items:center;border:0;border-radius:8px;background:#f7f4f0;color:#837c74;font:inherit;font-size:1.35rem;cursor:pointer}
.lc-warning-body{min-height:0;overflow:auto;padding:16px 20px 12px}
.lc-warning-item{padding:13px;border:1px solid #f0e3d5;border-radius:12px;background:#fffaf5;margin-bottom:10px}
.lc-warning-item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.lc-warning-item-top strong{font-size:.84rem;font-weight:750;overflow-wrap:anywhere;color:#4c3b30;line-height:1.4}
.lc-warning-item-top span{padding:4px 7px;border-radius:999px;background:#fce7e5;color:#a05b55;font-size:.66rem;white-space:nowrap;font-weight:700}
.lc-warning-item p{margin:9px 0 7px;font-size:.8rem;color:#655447;line-height:1.55;overflow-wrap:anywhere}
.lc-warning-item small{color:#8e8379;font-size:.7rem}
.lc-warning-hint{font-size:.74rem;color:#8d8379;line-height:1.5;margin:8px 0 0}
.lc-warning-error{margin-top:10px;color:#b24f4b;font-size:.77rem}
.lc-warning-footer{padding:13px 20px;display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;border-top:1px solid #eee9e4;background:#fffdfb}
.lc-warning-footer button{font:inherit;font-size:.81rem;font-weight:700;padding:10px 14px;border-radius:10px;cursor:pointer;min-height:39px}
.lc-warning-later{background:#fff;color:#736a60;border:1px solid #e8e1da}
.lc-warning-ack{background:#e99552;color:white;border:1px solid #e99552}
.lc-warning-footer button:focus-visible,.lc-warning-close:focus-visible{outline:3px solid rgba(233,153,81,.35);outline-offset:2px}
.lc-warning-footer button:disabled{opacity:.65;cursor:wait}
@media(max-width:440px){.lc-warning-overlay{padding:10px}.lc-warning-popup{border-radius:15px;max-height:calc(100dvh - 20px)}.lc-warning-header{padding:16px 14px 13px}.lc-warning-body{padding:14px 14px 10px}.lc-warning-footer{padding:11px 14px}.lc-warning-footer button{flex:1;padding:10px 8px}.lc-warning-heading h2{font-size:1rem}}
</style>
<script>
(() => {
    const overlay = document.getElementById('lcSellerWarningOverlay');
    if (!overlay) return;
    const dialog = overlay.querySelector('.lc-warning-popup');
    const ackButton = document.getElementById('lcWarningAcknowledge');
    const laterButton = document.getElementById('lcWarningLater');
    const closeButton = document.getElementById('lcWarningClose');
    const errorBox = document.getElementById('lcWarningError');
    const warningIds = <?= $warningJson ?>;
    const token = <?= json_encode($warningCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    dialog.focus();
    const closePopup = () => {
        overlay.remove();
        document.body.style.overflow = previousOverflow;
        if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    };
    closeButton.addEventListener('click', closePopup);
    laterButton.addEventListener('click', closePopup);
    document.addEventListener('keydown', function handleWarningKey(event) {
        if (!overlay.isConnected) {
            document.removeEventListener('keydown', handleWarningKey);
            return;
        }
        if (event.key === 'Escape') {
            closePopup();
            return;
        }
        if (event.key === 'Tab') {
            const focusables = [closeButton, laterButton, ackButton].filter(el => !el.disabled);
            if (!focusables.length) return;
            const current = focusables.indexOf(document.activeElement);
            if (event.shiftKey && current <= 0) {
                event.preventDefault();
                focusables[focusables.length - 1].focus();
            } else if (!event.shiftKey && current === focusables.length - 1) {
                event.preventDefault();
                focusables[0].focus();
            }
        }
    });
    ackButton.addEventListener('click', async () => {
        if (ackButton.disabled) return;
        ackButton.disabled = true;
        errorBox.hidden = true;
        const form = new URLSearchParams({
            warning_action: 'acknowledge',
            csrf_token: token,
            message_ids: JSON.stringify(warningIds)
        });
        try {
            const response = await fetch('seller_product_warnings.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: form.toString()
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save acknowledgement.');
            closePopup();
        } catch (error) {
            errorBox.textContent = error.message || 'Unable to save acknowledgement. Please try again.';
            errorBox.hidden = false;
        } finally {
            ackButton.disabled = false;
        }
    });
})();
</script>
<?php endif; ?>
