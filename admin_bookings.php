<?php
// admin_bookings.php - แอดมินดูรายการจองทั้งหมด (GET) ต้องมี token แอดมิน
// ตัวกรอง (ไม่ใส่ก็ได้): status, payment_status, booking_type, date, q, page, limit
// ตัวอย่าง: admin_bookings.php?status=pending&q=BK2611&page=1&limit=50
require 'config.php';
require 'auth.php';
require_method('GET');
require_auth(['admin']);

$where  = [];
$params = [];

$status = trim($_GET['status'] ?? '');
if ($status !== '') {
    if (!in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
        respond(['success' => false, 'message' => 'status ไม่ถูกต้อง'], 400);
    }
    $where[] = 'b.status = ?';
    $params[] = $status;
}

$pay = trim($_GET['payment_status'] ?? '');
if ($pay !== '') {
    if (!in_array($pay, ['unpaid', 'paid'], true)) {
        respond(['success' => false, 'message' => 'payment_status ไม่ถูกต้อง'], 400);
    }
    $where[] = 'b.payment_status = ?';
    $params[] = $pay;
}

$type = trim($_GET['booking_type'] ?? '');
if ($type !== '') {
    if (!in_array($type, ['bus', 'flight'], true)) {
        respond(['success' => false, 'message' => 'booking_type ไม่ถูกต้อง'], 400);
    }
    $where[] = 'b.booking_type = ?';
    $params[] = $type;
}

$date = trim($_GET['date'] ?? '');
if ($date !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        respond(['success' => false, 'message' => 'รูปแบบวันที่ต้องเป็น YYYY-MM-DD'], 400);
    }
    $where[] = 'b.travel_date = ?';
    $params[] = $date;
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $where[] = '(b.booking_id LIKE ? OR b.full_name LIKE ? OR b.email LIKE ? OR b.phone LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}

$limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// จำนวนรวมตามตัวกรอง
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// รายการ (limit/offset เป็นตัวเลขที่แปลงแล้ว ใส่ตรงได้อย่างปลอดภัย)
$stmt = $pdo->prepare(
    "SELECT b.id, b.booking_id, b.checkin_reference, b.booking_type, b.travel_date,
            b.departure_station, b.arrival_station, b.company_name, b.booked_seats, b.seat_count,
            b.full_name, b.phone, b.email, b.user_id, b.total_price,
            b.status, b.payment_status, b.payment_method, b.created_at,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = b.id ORDER BY p.payment_id DESC LIMIT 1) AS last_payment_status,
            (SELECT p.amount FROM payments p WHERE p.booking_id = b.id ORDER BY p.payment_id DESC LIMIT 1) AS last_payment_amount
     FROM bookings b
     $whereSql
     ORDER BY b.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['id']           = (int)$r['id'];
    $r['seat_count']   = (int)$r['seat_count'];
    $r['total_price']  = (float)$r['total_price'];
    $r['booked_seats'] = json_decode($r['booked_seats'], true) ?: [];
    if ($r['last_payment_amount'] !== null) {
        $r['last_payment_amount'] = (float)$r['last_payment_amount'];
    }
}
unset($r);

// สรุปภาพรวม (ทั้งระบบ ไม่ขึ้นกับตัวกรอง)
$s = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'pending')   AS pending,
            SUM(status = 'confirmed') AS confirmed,
            SUM(status = 'cancelled') AS cancelled
     FROM bookings"
)->fetch();
$revenue = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'completed'")->fetchColumn();
$waiting = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'processing'")->fetchColumn();

respond([
    'success' => true,
    'summary' => [
        'total_bookings'     => (int)$s['total'],
        'pending'            => (int)$s['pending'],
        'confirmed'          => (int)$s['confirmed'],
        'cancelled'          => (int)$s['cancelled'],
        'revenue'            => $revenue,
        'transfers_to_check' => $waiting,
    ],
    'page'     => $page,
    'limit'    => $limit,
    'total'    => $total,
    'bookings' => $rows,
]);
