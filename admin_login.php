<?php
// admin_login.php - แอดมินเข้าสู่ระบบ (POST)
// body: { "email": "...", "password": "..." }
// สำเร็จจะได้ token ไปใส่ Header:  Authorization: Bearer <token>
require 'config.php';
require 'auth.php';
require_method('POST');

$in       = get_input();
$email    = strtolower(trim($in['email'] ?? ''));
$password = $in['password'] ?? '';

if ($email === '' || $password === '') {
    respond(['success' => false, 'message' => 'กรุณากรอกอีเมลและรหัสผ่าน'], 400);
}

$stmt = $pdo->prepare(
    'SELECT id, member_code, firstname, lastname, phone, email, password, profile_image, status
     FROM admins WHERE email = ?'
);
$stmt->execute([$email]);
$a = $stmt->fetch();

if (!$a || !password_verify($password, $a['password'])) {
    respond(['success' => false, 'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 401);
}
if ($a['status'] !== 'active') {
    respond(['success' => false, 'message' => 'บัญชีนี้ถูกระงับ'], 403);
}

unset($a['password']);
$a['id'] = (int)$a['id'];

respond([
    'success' => true,
    'message' => 'เข้าสู่ระบบแอดมินสำเร็จ',
    'admin'   => $a,
    'token'   => make_token(['uid' => $a['id'], 'role' => 'admin']),
]);
