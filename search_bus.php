<?php
// search_bus.php - ค้นหารถทัวร์ (GET)
// ตัวอย่าง: search_bus.php?origin=หมอชิต&destination=เชียงใหม่&date=2026-12-01
// ถ้าส่ง date จะคำนวณจำนวนที่นั่งว่างให้ด้วย
require 'config.php';
require 'seats.php';
require_method('GET');

$origin      = trim($_GET['origin'] ?? '');
$destination = trim($_GET['destination'] ?? '');
$date        = trim($_GET['date'] ?? '');

if ($date !== '' && !valid_date($date)) {
    respond(['success' => false, 'message' => 'รูปแบบวันที่ต้องเป็น YYYY-MM-DD'], 400);
}

$sql = "SELECT bus_id, partner_id, company_name, bus_type, departure_station, arrival_station,
               departure_time, arrival_time, duration, seat_capacity, price
        FROM bus
        WHERE status = 'active'";
$params = [];

if ($origin !== '')      { $sql .= ' AND departure_station LIKE ?'; $params[] = "%$origin%"; }
if ($destination !== '') { $sql .= ' AND arrival_station LIKE ?';   $params[] = "%$destination%"; }

$sql .= ' ORDER BY departure_time ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['bus_id']        = (int)$r['bus_id'];
    $r['seat_capacity'] = (int)$r['seat_capacity'];
    $r['price']         = (float)$r['price'];
    if ($date !== '') {
        $booked = get_booked_seats($pdo, 'bus', $r['bus_id'], $date);
        $r['travel_date']     = $date;
        $r['booked_seats']    = $booked;
        $r['available_seats'] = max(0, $r['seat_capacity'] - count($booked));
    }
}
unset($r);

respond(['success' => true, 'count' => count($rows), 'buses' => $rows]);
