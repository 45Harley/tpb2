<?php
/**
 * Site Settings Helper
 * Read/write key-value settings from the site_settings table.
 */

function getSiteSetting($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function setSiteSetting($pdo, $key, $value, $userId = null) {
    $stmt = $pdo->prepare("
        UPDATE site_settings SET setting_value = ?, updated_by_user_id = ?
        WHERE setting_key = ?
    ");
    $stmt->execute([$value, $userId, $key]);
    return $stmt->rowCount() > 0;
}
