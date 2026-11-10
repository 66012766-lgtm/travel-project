<?php
// auth.php - ระบบ token แบบลงลายเซ็น (HMAC) ไม่ต้องใช้ตารางเพิ่ม
// ใช้คู่กับ config.php:  require 'config.php'; require 'auth.php';
//
// ฝั่งแอปส่ง token มาทาง Header:  Authorization: Bearer <token>

// !!! เปลี่ยนเป็นข้อความสุ่มยาว ๆ ของคุณเอง แล้วห้ามเปิดเผย !!!
const AUTH_SECRET = 'CHANGE_ME_to_a_long_random_string_2026';
const TOKEN_TTL   = 86400;   // อายุ token 24 ชั่วโมง

function b64url_encode(string $d): string {
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}
function b64url_decode(string $s): string {
    return (string)base64_decode(strtr($s, '-_', '+/'));
}

// สร้าง token จาก payload เช่น ['uid'=>1,'role'=>'admin']
function make_token(array $payload): string {
    $payload['exp'] = time() + TOKEN_TTL;
    $body = b64url_encode(json_encode($payload));
    $sig  = b64url_encode(hash_hmac('sha256', $body, AUTH_SECRET, true));
    return $body . '.' . $sig;
}

// อ่านและตรวจ token จาก Header คืน payload หรือ null ถ้าไม่ผ่าน
function read_token(): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $h = $v;
            }
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
        return null;
    }
    $parts = explode('.', trim($m[1]));
    if (count($parts) !== 2) {
        return null;
    }
    $body = $parts[0];
    $sig  = $parts[1];
    $expect = b64url_encode(hash_hmac('sha256', $body, AUTH_SECRET, true));
    if (!hash_equals($expect, $sig)) {
        return null;
    }
    $p = json_decode(b64url_decode($body), true);
    if (!is_array($p) || ($p['exp'] ?? 0) < time()) {
        return null;
    }
    return $p;
}

// บังคับให้ต้องมี token และ role ตรงกับที่อนุญาต ไม่ผ่านจะตอบ 401 ทันที
function require_auth(array $roles): array {
    $p = read_token();
    if (!$p || !in_array($p['role'] ?? '', $roles, true)) {
        respond(['success' => false, 'message' => 'ไม่มีสิทธิ์ หรือ token หมดอายุ กรุณาเข้าสู่ระบบใหม่'], 401);
    }
    return $p;
}
