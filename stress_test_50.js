import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

// ====================================================
// 1. إعدادات الضغط العالي
// ====================================================
export const options = {
    // 50 مستخدم وهمي يهجمون في نفس اللحظة
    vus: 50,

    // إجمالي 50 محاولة (ممكن تزودها لـ 200 لو المخزون كبير)
    iterations: 50,

    thresholds: {
        // لازم نسبة الفشل (HTTP 500) تكون 0%
        http_req_failed: ['rate==0.00'],
        // 95% من الطلبات لازم تخلص في أقل من 500 مللي ثانية
        http_req_duration: ['p(95)<500'],
    },
};

const BASE_URL = 'http://127.0.0.1:8000/api';
const PRODUCT_ID = 1;

// عدادات مخصصة للتقرير النهائي
let soldItems = new Counter('sold_items');
let failedHolds = new Counter('failed_holds'); // لما المخزون يخلص

export default function () {
    const params = { headers: { 'Content-Type': 'application/json' } };

    // ====================================================
    // Step 1: محاولة الحجز (Hold)
    // ====================================================
    let holdPayload = JSON.stringify({ product_id: PRODUCT_ID, qty: 1 });
    let holdRes = http.post(`${BASE_URL}/holds`, holdPayload, params);

    // في اختبار الضغط، وارد جداً إن الحجز يفشل (409 Conflict) لو المخزون خلص
    // احنا بس بنعتبره "فشل" لو السيرفر ضرب (500)
    check(holdRes, {
        'Hold Response Valid (201 or 4xx)': (r) => r.status === 201 || r.status >= 400
    });

    if (holdRes.status !== 201) {
        failedHolds.add(1);
        return; // لو فشل في الحجز (المخزون خلص)، يوقف المحاولة دي
    }

    // لو نجح في الحجز، نكمل باقي الخطوات
    let holdId = holdRes.json('data.hold_id');
    soldItems.add(1);

    // ====================================================
    // Step 2: عشوائية الدفع (نجاح/فشل)
    // ====================================================
    const isSuccess = Math.random() < 0.8; // 80% هينجحوا، 20% هيفشلوا
    const statusToSend = isSuccess ? 'success' : 'failed';

    // ====================================================
    // Step 3: السباق (Order vs Webhook)
    // ====================================================
    let idempotencyKey = `stress_${holdId}_${Math.random()}`;

    let responses = http.batch([
        // Request [0]: Create Order
        ['POST', `${BASE_URL}/orders`, JSON.stringify({
            hold_id: holdId,
        }), params],

        // Request [1]: Webhook
        ['POST', `${BASE_URL}/payments/webhook`, JSON.stringify({
            idempotency_key: idempotencyKey,
            data: {
                status: statusToSend,
                hold_id: holdId
            }
        }), {
            headers: { 'Content-Type': 'application/json' }
        }]
    ]);

    let orderRes = responses[0];
    let webhookRes = responses[1];

    check(orderRes, { 'Order Created (201)': (r) => r.status === 201 });
    check(webhookRes, { 'Webhook Handled (200/404)': (r) => r.status === 200 || r.status === 404 });

    // ====================================================
    // Step 4: أهم اختبار (No Overselling)
    // ====================================================
    // بنشيك على المخزون في كل لفة عشان نتأكد إنه عمره ما نزل تحت الصفر
    let resStock = http.get(`${BASE_URL}/products/${PRODUCT_ID}`);
    let currentStock = resStock.json('data.total_stock');

    check(resStock, {
        '⛔ CRITICAL: Stock is NOT Negative': (r) => currentStock >= 0,
    });
}
