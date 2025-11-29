import http from 'k6/http';
import { check, sleep } from 'k6';

// إعدادات الاختبار
export const options = {
    vus: 1,
    iterations: 1,
    thresholds: {
        checks: ['rate==1.00'],
    },
};

const BASE_URL = 'http://127.0.0.1:8000/api';
const PRODUCT_ID = 1;

export default function () {
    const params = { headers: { 'Content-Type': 'application/json' } };

    // ====================================================
    // 1. قراءة المخزون الأولي
    // ====================================================
    console.log('--- Step 1: Checking Initial Stock ---');
    let resInit = http.get(`${BASE_URL}/products/${PRODUCT_ID}`);

    check(resInit, { 'Get Product Success': (r) => r.status === 200 });

    let initialStock = resInit.json('data.total_stock');
    console.log(`Initial Stock: ${initialStock}`);

    if (initialStock <= 0) {
        console.error('Stock is 0! Cannot proceed with test.');
        return;
    }

    // ====================================================
    // 2. عملية الحجز (Create Hold)
    // ====================================================
    console.log('--- Step 2: Creating Hold ---');
    let holdPayload = JSON.stringify({ product_id: PRODUCT_ID, qty: 1 });
    let holdRes = http.post(`${BASE_URL}/holds`, holdPayload, params);

    check(holdRes, { 'Hold Created (201)': (r) => r.status === 201 });

    let holdId = holdRes.json('data.hold_id');

    // ====================================================
    // 3. تحديد السيناريو (Success vs Failed)
    // ====================================================
    // هنا بنختار عشوائياً: هل الدفع سينجح أم سيفشل؟
    const isSuccess = Math.random() < 0.5; // 50% احتمال
    const statusToSend = isSuccess ? 'success' : 'failed';

    console.log(`🎲 SCENARIO CHOSEN: [ ${statusToSend.toUpperCase()} ]`);

    // ====================================================
    // 4. السباق: الأوردر + الويب هوك
    // ====================================================
    console.log(`--- Step 3: Racing Order vs Webhook (${statusToSend}) ---`);

    let idempotencyKey = `key_test_${holdId}_${Math.random()}`;

    let responses = http.batch([
        // Request [0]: Create Order
        ['POST', `${BASE_URL}/orders`, JSON.stringify({
            hold_id: holdId,
        }), params],

        // Request [1]: Webhook
        ['POST', `${BASE_URL}/payments/webhook`, JSON.stringify({
            idempotency_key: idempotencyKey,
            data: {
                status: statusToSend, // بنبعت الحالة المتغيرة هنا
                hold_id: holdId
            }
        }), {
            headers: {
                'Content-Type': 'application/json',
            }
        }]
    ]);

    let orderRes = responses[0];
    let webhookRes = responses[1];

    check(orderRes, { 'Order Created (201)': (r) => r.status === 201 });
    check(webhookRes, { 'Webhook Handled': (r) => r.status === 200 || r.status === 404 });

    // ====================================================
    // 5. الحساب الختامي (Final Stock Check)
    // ====================================================
    console.log('--- Step 4: Verifying Final Stock ---');
    sleep(0.5); // وقت لضمان تحديث الداتابيز

    let resFinal = http.get(`${BASE_URL}/products/${PRODUCT_ID}`);
    let finalStock = resFinal.json('data.total_stock');

    console.log(`Initial: ${initialStock} -> Final: ${finalStock} | Expected Outcome: ${isSuccess ? 'Decrease' : 'Return'}`);

    if (isSuccess) {
        // سيناريو النجاح: المخزون لازم ينقص 1
        check(resFinal, {
            '✅ SUCCESS FLOW: Stock decreased by exactly 1': (r) => finalStock === (initialStock - 1),
        });
    } else {
        // سيناريو الفشل: المخزون لازم يرجع زي ما كان (الحجز اتلغى)
        check(resFinal, {
            '✅ FAILED FLOW: Stock released (Back to Initial)': (r) => finalStock === initialStock,
        });
    }
}
