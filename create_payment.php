<?php
// create_payment.php - ชำระเงินค่าตั๋ว (POST)
// ระบบจำลองการชำระเงิน (ยังไม่เชื่อม Payment Gateway จริง)
//   card / promptpay -> สำเร็จทันที (completed) การจองเปลี่ยนเป็น confirmed + paid
//   bank (โอนเงิน)   -> รอตรวจสอบ (processing) การจองยังเป็น pending
//
// body ตัวอย่าง (บัตร):
// { "user_id":1, "booking_id":"BK261107334089", "payment_method":"card",
//   "card_number":"4111111111111111", "card_holder_name":"SOMCHAI JAIDEE", "card_expiry":"12/29",
//   "promo_code":"WELCOME10" }          // promo_code ไม่ใส่ก็ได้
// พร้อมเพย์: { ..., "payment_method":"promptpay", "promptpay_phone":"0812345678" }
// โอนเงิน:   { ..., "payment_method":"bank", "bank_name":"กสิกรไทย",
//              "bank_account_number":"1234567890", "bank_account_name":"สมชาย ใจดี" }
//
// หมายเหตุความปลอดภัย: ไม่รับ CVV และเก็บเลขบัตร/เลขบัญชีเพียง 4 หลักท้าย
require 'config.php';
require_method('POST');

$in = get_input();

$user_id = (int)($in['user_id'] ?? 0);
$code    = trim($in['booking_id'] ?? '');
$method  = $in['payment_method'] ?? '';
$promo   = strtoupper(trim($in['promo_code'] ?? ''));

if ($user_id <= 0 || $code === '') {
    respond(['success' => false, 'message' => 'ต้องระบุ user_id และ booking_id'], 400);
}
if (!in_array($method, ['card', 'promptpay', 'bank'], true)) {
    respond(['success' => false, 'message' => 'payment_method ต้องเป็น card, promptpay หรือ bank'], 400);
}

$card_last4 = $card_holder = $card_expiry = $card_type = null;
$pp_phone = $bank_name = $bank_acc = $bank_acc_name = null;

// ---------- ตรวจข้อมูลตามวิธีชำระเงิน ----------
if ($method === 'card') {
    $num = preg_replace('/\D/', '', $in['card_number'] ?? '');
    $card_holder = trim($in['card_holder_name'] ?? '');
    $card_expiry = trim($in['card_expiry'] ?? '');

    if (strlen($num) < 13 || strlen($num) > 19) {
        respond(['success' => false, 'message' => 'เลขบัตรไม่ถูกต้อง'], 400);
    }
    if ($card_holder === '') {
        respond(['success' => false, 'message' => 'กรุณากรอกชื่อผู้ถือบัตร'], 400);
    }
    if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $card_expiry, $m)) {
        respond(['success' => false, 'message' => 'วันหมดอายุต้องเป็นรูปแบบ MM/YY'], 400);
    }
    if (('20' . $m[2] . $m[1]) < date('Ym')) {
        respond(['success' => false, 'message' => 'บัตรหมดอายุแล้ว'], 400);
    }
    $card_last4 = substr($num, -4);
    $first = $num[0];
    $card_type = $first === '4' ? 'Visa' : ($first === '5' ? 'Mastercard' : 'Other');

} elseif ($method === 'promptpay') {
    $pp_phone = preg_replace('/\D/', '', $in['promptpay_phone'] ?? '');
    if (!preg_match('/^0\d{9}$/', $pp_phone)) {
        respond(['success' => false, 'message' => 'เบอร์พร้อมเพย์ต้องเป็นเลข 10 หลัก ขึ้นต้นด้วย 0'], 400);
    }

} else { // bank
    $bank_name     = trim($in['bank_name'] ?? '');
    $acc           = preg_replace('/\D/', '', $in['bank_account_number'] ?? '');
    $bank_acc_name = trim($in['bank_account_name'] ?? '');
    if ($bank_name === '' || strlen($acc) < 10 || $bank_acc_name === '') {
        respond(['success' => false, 'message' => 'กรุณากรอกชื่อธนาคาร เลขบัญชี (อย่างน้อย 10 หลัก) และชื่อบัญชี'], 400);
    }
    $bank_acc = substr($acc, -4);
}

// ---------- เริ่ม Transaction ----------
$pdo->beginTransaction();
try {
    // ล็อกแถวการจอง กันกดจ่ายซ้อน
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE booking_id = ? AND user_id = ? FOR UPDATE');
    $stmt->execute([$code, $user_id]);
    $b = $stmt->fetch();

    if (!$b) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'ไม่พบการจองนี้'], 404);
    }
    if ($b['status'] === 'cancelled') {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'การจองนี้ถูกยกเลิกแล้ว'], 400);
    }
    if ($b['payment_status'] === 'paid') {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'การจองนี้ชำระเงินแล้ว'], 409);
    }

    // ---------- คิดส่วนลด ----------
    $total    = (float)$b['total_price'];
    $discount = 0.0;
    $promo_id = null;

    if ($promo !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM promotions
             WHERE code = ? AND active = 1
               AND (start_date IS NULL OR start_date <= CURDATE())
               AND (end_date IS NULL OR end_date >= CURDATE())
               AND (usage_limit IS NULL OR used_count < usage_limit)
             FOR UPDATE"
        );
        $stmt->execute([$promo]);
        $p = $stmt->fetch();

        if (!$p) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => 'โค้ดส่วนลดไม่ถูกต้อง หมดอายุ หรือใช้ครบแล้ว'], 400);
        }
        if ($total < (float)$p['min_price']) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => 'ยอดไม่ถึงขั้นต่ำของโค้ดนี้ (' . $p['min_price'] . ' บาท)'], 400);
        }
        $discount = ($p['discount_type'] === 'percent')
            ? round($total * (float)$p['discount_value'] / 100, 2)
            : (float)$p['discount_value'];
        $discount = min($discount, $total);
        $promo_id = (int)$p['promo_id'];
    }

    $amount = round($total - $discount, 2);
    $status = ($method === 'bank') ? 'processing' : 'completed';
    $txn    = 'TXN' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
    $desc   = "ชำระค่าตั๋ว {$b['booking_id']}" . ($promo !== '' ? " ใช้โค้ด $promo ลด $discount บาท" : '');
    $paidAt = ($status === 'completed') ? 'NOW()' : 'NULL';   // ค่าคงที่ ไม่ได้มาจากผู้ใช้

    // ---------- บันทึกการชำระเงิน ----------
    $stmt = $pdo->prepare(
        "INSERT INTO payments
         (booking_id, user_id, payment_method, amount, currency, card_number, card_holder_name,
          card_expiry, card_type, promptpay_phone, bank_name, bank_account_number, bank_account_name,
          payment_status, transaction_id, payment_description, ip_address, user_agent, paid_at)
         VALUES (?,?,?,?,'THB',?,?,?,?,?,?,?,?,?,?,?,?,?,$paidAt)"
    );
    $stmt->execute([
        (int)$b['id'], $user_id, $method, $amount,
        $card_last4, $card_holder, $card_expiry, $card_type,
        $pp_phone, $bank_name, $bank_acc, $bank_acc_name,
        $status, $txn, $desc,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
    $payment_id = (int)$pdo->lastInsertId();

    // ---------- บันทึก log ----------
    $stmt = $pdo->prepare('INSERT INTO payment_logs (payment_id, status, message, response_data) VALUES (?,?,?,?)');
    $stmt->execute([
        $payment_id, $status,
        $status === 'completed' ? 'ชำระเงินสำเร็จ (ระบบจำลอง)' : 'รอตรวจสอบการโอนเงิน',
        json_encode(['transaction_id' => $txn, 'amount' => $amount, 'discount' => $discount, 'method' => $method], JSON_UNESCAPED_UNICODE),
    ]);

    // ---------- อัปเดตการจอง ----------
    if ($status === 'completed') {
        $stmt = $pdo->prepare("UPDATE bookings SET status='confirmed', payment_status='paid', payment_method=? WHERE id=?");
    } else {
        $stmt = $pdo->prepare("UPDATE bookings SET payment_method=? WHERE id=?");
    }
    $stmt->execute([$method, (int)$b['id']]);

    // ---------- นับการใช้โค้ดส่วนลด ----------
    if ($promo_id !== null) {
        $pdo->prepare('UPDATE promotions SET used_count = used_count + 1 WHERE promo_id = ?')->execute([$promo_id]);
    }

    // ---------- แจ้งเตือนผู้ใช้ ----------
    $pdo->prepare("INSERT INTO notification_status (user_id, notification_id, notification_type) VALUES (?,?, 'payment')")
        ->execute([$user_id, $txn]);

    $pdo->commit();

    respond([
        'success' => true,
        'message' => $status === 'completed' ? 'ชำระเงินสำเร็จ' : 'รับข้อมูลการโอนแล้ว รอตรวจสอบ',
        'payment' => [
            'payment_id'     => $payment_id,
            'transaction_id' => $txn,
            'booking_id'     => $b['booking_id'],
            'method'         => $method,
            'original_price' => $total,
            'discount'       => $discount,
            'amount'         => $amount,
            'payment_status' => $status,
            'booking_status' => $status === 'completed' ? 'confirmed' : $b['status'],
        ],
    ], 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการชำระเงิน', 'detail' => $e->getMessage()], 500);
}
