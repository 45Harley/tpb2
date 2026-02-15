<?php
/**
 * TPB Cookie Helper
 * =================
 * One place for cookie settings. Every login/logout path uses this.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/set-cookie.php';
 *   tpbSetCookie('tpb_user_id', $userId);              // 30-day default
 *   tpbSetCookie('tpb_user_id', $userId, 31536000);    // 1 year
 *   tpbClearCookie('tpb_user_id');                      // expire it
 *   tpbSetLoginCookies($userId, $sessionId, $expiry);  // all auth cookies at once
 */

/**
 * Set a TPB cookie with standard options.
 *
 * @param string $name    Cookie name
 * @param mixed  $value   Cookie value
 * @param int    $maxAge  Seconds from now (default 30 days)
 */
function tpbSetCookie($name, $value, $maxAge = 2592000) {
    setcookie($name, $value, [
        'expires'  => time() + $maxAge,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly'  => false,
        'samesite' => 'Lax'
    ]);
}

/**
 * Clear a TPB cookie.
 */
function tpbClearCookie($name) {
    setcookie($name, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly'  => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Set all auth cookies at once (login, magic link, invite accept).
 *
 * @param int    $userId        User ID
 * @param string $sessionId     Device session token
 * @param int    $maxAge        Seconds from now (default 30 days)
 * @param bool   $emailVerified Also set email_verified cookie (default true)
 */
function tpbSetLoginCookies($userId, $sessionId, $maxAge = 2592000, $emailVerified = true) {
    tpbSetCookie('tpb_civic_session', $sessionId, $maxAge);
    tpbSetCookie('tpb_user_id', $userId, $maxAge);
    if ($emailVerified) {
        tpbSetCookie('tpb_email_verified', '1', $maxAge);
    }
}

/** Standard expiry constants */
define('TPB_COOKIE_30_DAYS', 2592000);
define('TPB_COOKIE_1_YEAR', 31536000);
