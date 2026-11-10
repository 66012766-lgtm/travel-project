<?php
// create_booking.php - จองตั๋ว (POST)
// body ตัวอย่าง:
// {
//   "user_id": 1,
//   "booking_type": "bus",            // "bus" หรือ "flight"
//   "trip_id": 1,                     // bus_id หรือ flight_id
//   "travel_date": "2026-12-01",
//   "seats": ["5", "6"],              // รถทัวร์ใช้เลข 1..seat_capacity, เที่ยวบินใช้ได้ เช่น "12A"
//   "passengers": [ {"name":"สมชาย ใจดี","id_card":"1101700000001"},
//                   {"name":"สมหญิง ใจดี","id_card":"1101700000002"} ],
//   "contact": {"full_name":"สมชาย ใจดี","phone":"0812345678","email":"a@b.com"}   // ไม่ใส่ก็ได้ จะใช้ข้อมูลสมาชิก
// }
require 'config.php';
require 'seats.php';
require_method('POST');

$in = get_input();

$user_id    = (int)($in['user_id'] ?? 0);
$type       = $in['booking_type'] ?? '';
$trip_id    = (int)($in['trip_id'] ?? 0);
$date       = trim($in['travel_date'] ?? '');
$seats      = $in['seats'] ?? [];
$passengers = $in['passengers'] ?? [];
$contact    = $in['contact'] ?? [];

// ---------- ตรวจสอบข้อมูลเบื้องต้น ----------
if (!in_array($type, ['bus', 'flight'], true)) {
    respond(['success' => false, 'message' => 'booking_type ต้องเป็น bus หรือ flight'], 400);
}
if ($user_id <= 0 || $trip_id <= 0) {
    respond(['success' => false, 'message' => 'ข้อมูล user_id หรือ trip_id ไม่ถูกต้อง'], 400);
}
if (!valid_date($date) || $date < date('Y-m-d')) {
    respond(['success' => false, 'message' => 'วันเดินทางไม่ถูกต้อง หรือเป็นวันที่ผ่านมาแล้ว'], 400);
}
if (!is_array($seats) || count($seats) === 0) {
    respond(['success' => false, 'message' => 'กรุณาเลือกที่นั่งอย่างน้อย 1 ที่'], 400);
}
$seats = array_values(array_unique(array_map('strval', $seats)));

if (!is_array($passengers) || count($passengers) !== count($seats)) {
    respond(['success' => false, 'message' => 'จำนวนผู้โดยสารต้องเท่ากับจำนวนที่นั่ง'], 400);
}
foreach ($passengers as $p) {
    if (trim($p['name'] ?? '') === '') {
        respond(['success' => false, 'message' => 'กรุณากรอกชื่อผู้โดยสารให้ครบ'], 400);
    }
}

// ---------- ตรวจว่ามีสมาชิกจริง ----------
$stmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE user_id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
    respond(['success' => false, 'message' => 'ไม่พบสมาชิก'], 404);
}

$contact_name  = trim($contact['full_name'] ?? '') ?: $user['full_name'];
$contact_phone = trim($contact['phone'] ?? '')     ?: $user['phone'];
$contact_email = trim($contact['email'] ?? '')     ?: $user['email'];

// ---------- เริ่ม Transaction: ล็อกแถวเที่ยวเดินทาง กันจองที่นั่งซ้ำ ----------
$pdo->beginTransaction();
try {
    if ($type === 'bus') {
        $stmt = $pdo->prepare("SELECT * FROM bus WHERE bus_id = ? AND status = 'active' FOR UPDATE");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM flights WHERE flight_id = ? AND active = 1 AND status = 'active' FOR UPDATE");
    }
    $stmt->execute([$trip_id]);
    $trip = $stmt->fetch();

    if (!$trip) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'ไม่พบเที่ยวเดินทางนี้ หรือปิดให้จองแล้ว'], 404);
    }

    // รถทัวร์: ที่นั่งต้องเป็นเลข 1..seat_capacity
    if ($type === 'bus') {
        $cap = (int)$trip['seat_capacity'];
        foreach ($seats as $s) {
            if (!ctype_digit($s) || (int)$s < 1 || (int)$s > $cap) {
                $pdo->rollBack();
                respond(['success' => false, 'message' => "ที่นั่ง $s ไม่ถูกต้อง (มี 1-$cap)"], 400);
            }
        }
    }

    // เช็กที่นั่งซ้ำ
    $taken    = get_booked_seats($pdo, $type, $trip_id, $date);
    $conflict = array_values(array_intersect($seats, $taken));
    if ($conflict) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'ที่นั่งถูกจองไปแล้ว: ' . implode(', ', $conflict),
            'taken'   => $conflict,
        ], 409);
    }

    // ราคาคิดจากฐานข้อมูลเสมอ (ไม่เชื่อราคาที่แอปส่งมา)
    if ($type === 'bus') {
        $unit = (float)$trip['price'];
        $from = $trip['departure_station'];
        $to   = $trip['arrival_station'];
        $company = $trip['company_name'];
        $gate = null;
    } else {
        $unit = (float)($trip['total_price'] ?? $trip['price']);
        $from = $trip['origin'];
        $to   = $trip['destination'];
        $company = $trip['airline'];
        $gate = $trip['gate'];
    }
    $total = $unit * count($seats);

    // เวลาออก-ถึง (ถ้าถึงก่อนออก แปลว่าข้ามวัน)
    $dep = new DateTime($date . ' ' . $trip['departure_time']);
    $arr = new DateTime($date . ' ' . $trip['arrival_time']);
    if ($arr <= $dep) {
        $arr->modify('+1 day');
    }

    // สร้างรหัสการจอง
    $booking_code = 'BK' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $ref = '';
    for ($i = 0; $i < 6; $i++) {
        $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $first = $passengers[0];

    $stmt = $pdo->prepare(
        "INSERT INTO bookings
         (booking_id, checkin_reference, booking_type, bus_id, bus_type, flight_id, travel_date,
          departure_station, arrival_station, gate_platform, departure_time, arrival_time,
          company_name, booked_seats, seat_count, full_name, phone, email, id_card, user_id,
          passenger_data, contact_data, total_price, status, payment_status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending','unpaid')"
    );
    $stmt->execute([
        $booking_code,
        $ref,
        $type,
        $type === 'bus'    ? $trip_id : null,
        $type === 'bus'    ? $trip['bus_type'] : null,
        $type === 'flight' ? $trip_id : null,
        $date,
        $from,
        $to,
        $gate,
        $dep->format('Y-m-d H:i:s'),
        $arr->format('Y-m-d H:i:s'),
        $company,
        json_encode($seats, JSON_UNESCAPED_UNICODE),
        count($seats),
        $contact_name,
        $contact_phone,
        $contact_email,
        $first['id_card'] ?? null,
        $user_id,
        json_encode($passengers, JSON_UNESCAPED_UNICODE),
        json_encode(['full_name' => $contact_name, 'phone' => $contact_phone, 'email' => $contact_email], JSON_UNESCAPED_UNICODE),
        $total,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'จองตั๋วสำเร็จ กรุณาชำระเงิน',
        'booking' => [
            'id'                => $new_id,
            'booking_id'        => $booking_code,
            'checkin_reference' => $ref,
            'booking_type'      => $type,
            'travel_date'       => $date,
            'from'              => $from,
            'to'                => $to,
            'seats'             => $seats,
            'total_price'       => $total,
            'status'            => 'pending',
            'payment_status'    => 'unpaid',
        ],
    ], 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการจอง', 'detail' => $e->getMessage()], 500);
}
