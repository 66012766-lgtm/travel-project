<?php
// my_bookings.php - ประวัติการจองของสมาชิก (GET)
// ตัวอย่าง: my_bookings.php?user_id=1
require 'config.php';
require_method('GET');

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    respond(['success' => false, 'message' => 'ต้องระบุ user_id'], 400);
}

$stmt = $pdo->prepare(
    "SELECT id, booking_id, checkin_reference, booking_type, travel_date,
            departure_station, arrival_station, departure_time, arrival_time,
            company_name, booked_seats, seat_count, total_price,
            status, payment_status, payment_method, created_at
     FROM bookings
     WHERE user_id = ?
     ORDER BY created_at DESC"
);
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['id']           = (int)$r['id'];
    $r['seat_count']   = (int)$r['seat_count'];
    $r['total_price']  = (float)$r['total_price'];
    $r['booked_seats'] = json_decode($r['booked_seats'], true) ?: [];
}
unset($r);

respond(['success' => true, 'count' => count($rows), 'bookings' => $rows]);
