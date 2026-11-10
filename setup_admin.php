<?php
// setup_admin.php - สร้างบัญชีแอดมินคนแรก (รันจากบรรทัดคำสั่งเท่านั้น เปิดผ่านเบราว์เซอร์ไม่ได้)
//
// วิธีใช้ (PowerShell):
//   C:\xampp\php\php.exe C:\xampp\htdocs\travel_api\setup_admin.php "admin@example.com" "Admin@1234" "Admin" "System"
//   ลำดับ: อีเมล รหัสผ่าน ชื่อ นามสกุล
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if ($argc < 5) {
    exit("ใช้งาน: php setup_admin.php <email> <password> <firstname> <lastname>\n");
}

list(, $email, $password, $first, $last) = $argv;
$email = strtolower(trim($email));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
    exit("อีเมลไม่ถูกต้อง หรือรหัสผ่านสั้นกว่า 6 ตัวอักษร\n");
}

try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=travel_booking;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        exit("มีแอดมินอีเมลนี้อยู่แล้ว\n");
    }

    $code = 'AD' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare(
        "INSERT INTO admins (member_code, firstname, lastname, email, password, status)
         VALUES (?, ?, ?, ?, ?, 'active')"
    );
    $stmt->execute([$code, $first, $last, $email, password_hash($password, PASSWORD_DEFAULT)]);

    echo "สร้างแอดมินสำเร็จ\n";
    echo "  รหัสสมาชิก: $code\n";
    echo "  อีเมล: $email\n";
} catch (Throwable $e) {
    echo 'เกิดข้อผิดพลาด: ' . $e->getMessage() . "\n";
}
