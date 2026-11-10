<?php
// seats.php - ฟังก์ชันช่วยเรื่องที่นั่ง/วันที่ (ใช้ร่วมกันหลายไฟล์)

// คืนรายการที่นั่งที่ถูกจองแล้วของเที่ยวนั้นในวันนั้น (ไม่นับที่ยกเลิก)
function get_booked_seats(PDO $pdo, string $type, int $tripId, string $date): array {
    $col = ($type === 'bus') ? 'bus_id' : 'flight_id';
    $stmt = $pdo->prepare(
        "SELECT booked_seats FROM bookings
         WHERE booking_type = ? AND $col = ? AND travel_date = ? AND status <> 'cancelled'"
    );
    $stmt->execute([$type, $tripId, $date]);

    $seats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            foreach ($arr as $s) {
                $seats[] = (string)$s;
            }
        }
    }
    return $seats;
}

// ตรวจรูปแบบวันที่ YYYY-MM-DD
function valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}
