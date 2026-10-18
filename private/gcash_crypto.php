<?php


declare(strict_types=1);

function gcashKey(): string
{
    $encoded = getenv('LOKALCRAFT_GCASH_KEY');
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;

    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('GCash encryption key is missing or invalid.');
    }

    return $key;
}

function encryptGcashNumber(string $number): string
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP OpenSSL extension is required for GCash encryption.');
    }

    if (!preg_match('/^09[0-9]{9}$/D', $number)) {
        throw new InvalidArgumentException('Invalid GCash mobile number.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $number,
        'aes-256-gcm',
        gcashKey(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'lokalcraft-gcash-number:v1',
        16
    );

    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('GCash encryption failed.');
    }

    return 'GCM1' . $nonce . $tag . $ciphertext;
}

function decryptGcashNumber(string $encrypted): string
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP OpenSSL extension is required for GCash decryption.');
    }

    if (strlen($encrypted) < 33 || substr($encrypted, 0, 4) !== 'GCM1') {
        throw new RuntimeException('GCash number has an unknown encryption format.');
    }

    $nonce = substr($encrypted, 4, 12);
    $tag = substr($encrypted, 16, 16);
    $ciphertext = substr($encrypted, 32);
    $number = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        gcashKey(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'lokalcraft-gcash-number:v1'
    );

    if ($number === false || !preg_match('/^09[0-9]{9}$/D', $number)) {
        throw new RuntimeException('GCash decryption failed: invalid key or modified data.');
    }

    return $number;
}
