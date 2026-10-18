<?php
// LokalCraft's private-storage configuration. Include this from upload/view handlers.
// In XAMPP, it uses the existing LokalCraft/private directory.
// For Hostinger, MOVE private/ outside public_html and point this setting to it.
$privateBase = getenv('LOKALCRAFT_PRIVATE_DIR');
if ($privateBase === false || trim($privateBase) === '') {
    $privateBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private';
}
$privateBase = rtrim($privateBase, '/\\');
define('LOKALCRAFT_PRIVATE_ROOT', $privateBase);
define('LOKALCRAFT_SELLER_DOCUMENT_ROOT', $privateBase . DIRECTORY_SEPARATOR . 'seller_documents');
