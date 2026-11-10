<?php
// login.php - เข้าสู่ระบบ (POST)
// body: { "email": "...", "password": "..." }
require 'config.php';
require 'auth.php';
require_method('POST');

$in = get_input();

$email    = strtolower(trim($in['email'] ?? ''));
$password = $in['password'] ?? '';

if ($email === '' || $password === '') {
    respond(['success' => false, 'message' => 'กรุณากรอกอีเมลและรหัสผ่าน'], 400);
}

$stmt = $pdo->prepare('SELECT user_id, full_name, email, password, phone, role FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

// ข้อความเดียวกันทั้งกรณีไม่พบอีเมล/รหัสผิด เพื่อไม่ให้เดาอีเมลได้
if (!$user || !password_verify($password, $user['password'])) {
    respond(['success' => false, 'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 401);
}

unset($user['password']);   // ไม่ส่งรหัสผ่านกลับไปเด็ดขาด
$user['user_id'] = (int)$user['user_id'];

respond([
    'success' => true,
    'message' => 'เข้าสู่ระบบสำเร็จ',
    'user'    => $user,
    'token'   => make_token(['uid' => $user['user_id'], 'role' => ($user['role'] === 'admin' ? 'admin' : 'customer')]),
]);
