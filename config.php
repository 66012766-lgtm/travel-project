<?php
// config.php - ตั้งค่าเชื่อมต่อฐานข้อมูล + ฟังก์ชันช่วยเหลือกลาง
// ทุกไฟล์ API จะ require ไฟล์นี้

// ---------- CORS (ให้แอป/เว็บเรียกข้ามโดเมนได้) ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- เชื่อมต่อ MySQL ด้วย PDO ----------
$DB_HOST = '127.0.0.1';
$DB_NAME = 'travel_booking';
$DB_USER = 'root';
$DB_PASS = '';          // XAMPP ค่าเริ่มต้นไม่มีรหัสผ่าน

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']);
    exit;
}

// ---------- ฟังก์ชันช่วยเหลือ ----------

// อ่าน JSON body ที่แอปส่งมา
function get_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ตอบกลับเป็น JSON แล้วจบการทำงาน
function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// บังคับให้ใช้เมธอดที่กำหนดเท่านั้น
function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        respond(['success' => false, 'message' => "ต้องใช้เมธอด $method"], 405);
    }
}
