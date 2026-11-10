<?php
// admin_confirm_payment.php - แอดมินอนุมัติ/ปฏิเสธการโอนเงิน (POST) ต้องมี token แอดมิน
// body: { "booking_id": "BK261107334089", "action": "approve" }   // หรือ "reject"
require 'config.php';
require 'auth.php';
require_method('POST');
require_auth(['admin']);

$in     = get_input();
$code   = trim($in['booking_id'] ?? '');
$action = $in['action'] ?? '';

if ($code === '' || !in_array($action, ['approve', 'reject'], true)) {
    respond(['success' => false, 'message' => 'ต้องระบุ booking_id และ action (approve หรือ reject)'], 400);
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
    if ($b['status'] === 'cancelled') {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'การจองนี้ถูกยกเลิกแล้ว'], 400);
    }

    // หารายการโอนที่รอตรวจสอบล่าสุด
    $stmt = $pdo->prepare(
        "SELECT * FROM payments WHERE booking_id = ? AND payment_status = 'processing'
         ORDER BY payment_id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([(int)$b['id']]);
    $p = $stmt->fetch();
    if (!$p) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'ไม่มีรายการชำระเงินที่รอตรวจสอบสำหรับการจองนี้'], 404);
    }

    if ($action === 'approve') {
        $pdo->prepare("UPDATE payments SET payment_status = 'completed', paid_at = NOW() WHERE payment_id = ?")
            ->execute([(int)$p['payment_id']]);
        $pdo->prepare("UPDATE bookings SET status = 'confirmed', payment_status = 'paid' WHERE id = ?")
            ->execute([(int)$b['id']]);
        $newStatus = 'completed';
        $msg = 'แอดมินอนุมัติการโอนเงิน';
    } else {
        $pdo->prepare("UPDATE payments SET payment_status = 'failed' WHERE payment_id = ?")
            ->execute([(int)$p['payment_id']]);
        $newStatus = 'failed';
        $msg = 'แอดมินปฏิเสธการโอนเงิน';
    }

    $pdo->prepare('INSERT INTO payment_logs (payment_id, status, message, response_data) VALUES (?,?,?,?)')
        ->execute([
            (int)$p['payment_id'], $newStatus, $msg,
            json_encode(['action' => $action, 'booking_id' => $code], JSON_UNESCAPED_UNICODE),
        ]);

    if ($b['user_id'] !== null) {
        $pdo->prepare("INSERT INTO notification_status (user_id, notification_id, notification_type) VALUES (?,?, 'payment')")
            ->execute([(int)$b['user_id'], $p['transaction_id'] ?: $code]);
    }

    $pdo->commit();

    respond([
        'success' => true,
        'message' => $msg,
        'result'  => [
            'booking_id'     => $code,
            'payment_id'     => (int)$p['payment_id'],
            'payment_status' => $newStatus,
            'booking_status' => $action === 'approve' ? 'confirmed' : $b['status'],
        ],
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['success' => false, 'message' => 'เกิดข้อผิดพลาด', 'detail' => $e->getMessage()], 500);
}
