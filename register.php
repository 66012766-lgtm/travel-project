<?php
// register.php - สมัครสมาชิก (POST)
// body: { "full_name": "...", "email": "...", "password": "...", "phone": "..." }
require 'config.php';
require_method('POST');

$in = get_input();

$full_name = trim($in['full_name'] ?? '');
$email     = strtolower(trim($in['email'] ?? ''));
$password  = $in['password'] ?? '';
$phone     = trim($in['phone'] ?? '');

// ---------- ตรวจสอบข้อมูล ----------
if ($full_name === '' || $email === '' || $password === '') {
    respond(['success' => false, 'message' => 'กรุณากรอกชื่อ อีเมล และรหัสผ่านให้ครบ'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง'], 400);
}
if (strlen($password) < 6) {
    respond(['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร'], 400);
}

// ---------- เช็กอีเมลซ้ำ ----------
$stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    respond(['success' => false, 'message' => 'อีเมลนี้ถูกใช้งานแล้ว'], 409);
}

// ---------- บันทึก (เข้ารหัสผ่านก่อนเสมอ) ----------
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, email, password, phone, role)
     VALUES (?, ?, ?, ?, "customer")'
);
$stmt->execute([$full_name, $email, $hash, $phone]);

respond([
    'success' => true,
    'message' => 'สมัครสมาชิกสำเร็จ',
    'user' => [
        'user_id'   => (int)$pdo->lastInsertId(),
        'full_name' => $full_name,
        'email'     => $email,
        'phone'     => $phone,
        'role'      => 'customer',
    ],
], 201);
