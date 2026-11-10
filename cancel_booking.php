<?php
// cancel_booking.php - ยกเลิกการจอง (POST) ต้องมี token (ลูกค้ายกเลิกได้เฉพาะของตัวเอง / แอดมินยกเลิกได้ทุกรายการ)
// body: { "booking_id": "BK261107334089" }
// ที่นั่งจะว่างทันที (ระบบไม่นับการจองที่ยกเลิก) ถ้าชำระแล้วจะบันทึกเป็นคืนเงิน (ระบบจำลอง)
require 'config.php';
require 'auth.php';
require_method('POST');
$auth = require_auth(['customer', 'admin']);

$in   = get_input();
$code = trim($in['booking_id'] ?? '');
if ($code === '') {
    respond(['success' => false, 'message' => 'ต้องระบุ booking_id'], 400);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE booking_id = ? FOR UPDATE');
    $stmt->execute([$code]);
    $b = $stmt->fetch();

    if (!$b) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'ไม่พบการจองนี้'], 404);
    }
    if ($auth['role'] === 'customer' && (int)$b['user_id'] !== (int)$auth['uid']) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'ไม่มีสิทธิ์ยกเลิกการจองนี้'], 403);
    }
    if ($b['status'] === 'cancelled') {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'การจองนี้ถูกยกเลิกไปแล้ว'], 409);
    }
    if ($b['travel_date'] < date('Y-m-d')) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'ไม่สามารถยกเลิกการจองที่เลยวันเดินทางแล้ว'], 400);
    }

    $refunded = 0.0;

    // ถ้าชำระแล้ว -> คืนเงิน (จำลอง)
    $stmt = $pdo->prepare(
        "SELECT payment_id, amount FROM payments
         WHERE booking_id = ? AND payment_status = 'completed'
         ORDER BY payment_id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([(int)$b['id']]);
    $p = $stmt->fetch();
    if ($p) {
        $pdo->prepare("UPDATE payments SET payment_status = 'refunded' WHERE payment_id = ?")
            ->execute([(int)$p['payment_id']]);
        $pdo->prepare('INSERT INTO payment_logs (payment_id, status, message, response_data) VALUES (?,?,?,?)')
            ->execute([
                (int)$p['payment_id'], 'refunded', 'คืนเงินจากการยกเลิกการจอง (ระบบจำลอง)',
                json_encode(['by' => $auth['role'], 'booking_id' => $code], JSON_UNESCAPED_UNICODE),
            ]);
        $refunded = (float)$p['amount'];
    }

    // รายการโอนที่ยังรอตรวจสอบ -> ยกเลิกด้วย
    $pdo->prepare("UPDATE payments SET payment_status = 'failed' WHERE booking_id = ? AND payment_status IN ('pending','processing')")
        ->execute([(int)$b['id']]);

    $pdo->prepare("UPDATE bookings SET status = 'cancelled', payment_status = 'unpaid' WHERE id = ?")
        ->execute([(int)$b['id']]);

    $pdo->commit();

    respond([
        'success' => true,
        'message' => $refunded > 0 ? 'ยกเลิกการจองและบันทึกการคืนเงินแล้ว' : 'ยกเลิกการจองแล้ว',
        'result'  => [
            'booking_id'      => $code,
            'booking_status'  => 'cancelled',
            'refunded_amount' => $refunded,
            'released_seats'  => json_decode($b['booked_seats'], true) ?: [],
        ],
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['success' => false, 'message' => 'เกิดข้อผิดพลาด', 'detail' => $e->getMessage()], 500);
}
