<?php
// search_flights.php - ค้นหาเที่ยวบิน (GET)
// ตัวอย่าง: search_flights.php?origin=กรุงเทพ&destination=เชียงใหม่&date=2026-12-01
require 'config.php';
require 'seats.php';
require_method('GET');

$origin      = trim($_GET['origin'] ?? '');
$destination = trim($_GET['destination'] ?? '');
$date        = trim($_GET['date'] ?? '');
$class       = trim($_GET['class_type'] ?? '');

if ($date !== '' && !valid_date($date)) {
    respond(['success' => false, 'message' => 'รูปแบบวันที่ต้องเป็น YYYY-MM-DD'], 400);
}

$sql = "SELECT flight_id, partner_id, logo, airline, aircraft_type, origin, destination,
               departure_terminal, arrival_terminal, departure_time, arrival_time, duration,
               class_type, baggage_allowance, checked_baggage, meal_type,
               COALESCE(total_price, price) AS price
        FROM flights
        WHERE active = 1 AND status = 'active'";
$params = [];

if ($origin !== '')      { $sql .= ' AND origin LIKE ?';      $params[] = "%$origin%"; }
if ($destination !== '') { $sql .= ' AND destination LIKE ?'; $params[] = "%$destination%"; }
if ($class !== '')       { $sql .= ' AND class_type = ?';     $params[] = $class; }

$sql .= ' ORDER BY departure_time ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['flight_id'] = (int)$r['flight_id'];
    $r['price']     = (float)$r['price'];
    if ($date !== '') {
        $r['travel_date']  = $date;
        $r['booked_seats'] = get_booked_seats($pdo, 'flight', $r['flight_id'], $date);
    }
}
unset($r);

respond(['success' => true, 'count' => count($rows), 'flights' => $rows]);
