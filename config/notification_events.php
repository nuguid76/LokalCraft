<?php
function lokalcraftCreateNotification(
    mysqli $conn,
    int $recipientUserId,
    string $type,
    string $title,
    string $message,
    string $referenceType,
    int $referenceId,
    string $eventKey
): bool {
    if ($recipientUserId <= 0 || $referenceId <= 0) {
        throw new InvalidArgumentException('Invalid notification recipient or reference.');
    }
    if (!in_array($referenceType, ['order', 'seller_application'], true)) {
        throw new InvalidArgumentException('Unsupported notification reference type.');
    }
    if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $type)) {
        throw new InvalidArgumentException('Unsupported notification type.');
    }
    $title = trim($title);
    $message = trim($message);
    $eventKey = trim($eventKey);
    if ($title === '' || $message === '' || $eventKey === ''
        || strlen($title) > 150 || strlen($eventKey) > 190) {
        throw new InvalidArgumentException('Invalid notification content.');
    }
    $stmt = $conn->prepare(
        'INSERT INTO notifications
          (user_id, type, title, message, reference_type, reference_id, event_key)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE notification_id = notification_id'
    );
    $stmt->bind_param(
        'issssis',
        $recipientUserId,
        $type,
        $title,
        $message,
        $referenceType,
        $referenceId,
        $eventKey
    );
    try {
        $stmt->execute();
        return $stmt->affected_rows === 1;
    } finally {
        $stmt->close();
    }
}
