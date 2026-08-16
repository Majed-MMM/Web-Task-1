<?php
/**
 * api/assistant.php
 * ------------------------------------------------------------
 * الخادم الخلفي (Backend) الذي يستقبل نص المستخدم من app.js
 * ثم يستدعي Google Gemini API بمفتاح سرّي (لا يظهر في المتصفح)
 * ويعيد الرد إلى الواجهة الأمامية بصيغة JSON.
 *
 * ملاحظة عن اسم الملف:
 *   كان اسم هذا الملف في الأصل "chat.php"، لكن استضافة InfinityFree
 *   تحظر تلقائيًا أي رابط يحتوي كلمة "chat" (كي تمنع سكربتات الدردشة
 *   المباشرة التي ترهق السيرفر بالتحديث المستمر)، فكانت كل محاولة
 *   وصول لهذا الملف ترجع 403 Forbidden من InfinityFree نفسها قبل أن
 *   يصل الطلب لكودنا أصلًا. لذلك تم تغيير الاسم إلى "assistant.php".
 * ------------------------------------------------------------
 */

// 1) نسمح فقط بطلبات POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'الطريقة غير مسموحة، استخدم POST فقط']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 2) تحميل ملف الإعدادات (يحتوي على مفتاح Gemini السرّي)
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'ملف الإعدادات config.php غير موجود. أنشئه من config.sample.php وضع مفتاحك فيه.'
    ]);
    exit;
}
require_once $configPath; // يوفر الثابت GEMINI_API_KEY

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'ضع_مفتاحك_هنا') {
    http_response_code(500);
    echo json_encode(['error' => 'مفتاح Gemini API غير مضبوط في config.php']);
    exit;
}

// 3) قراءة الجسم الخام (JSON) القادم من fetch() في app.js
$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($input['prompt'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'لم يتم إرسال حقل prompt بصيغة JSON صحيحة']);
    exit;
}

$prompt = trim($input['prompt']);
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'النص المُرسل فارغ']);
    exit;
}

// 4) تجهيز طلب Gemini REST API
$model = 'gemini-3.5-flash'; // gemini-2.0-flash تم إيقافه من Google، هذا هو النموذج المستقر الحالي
$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ]
];

// 5) إرسال الطلب عبر cURL
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 6) معالجة أخطاء الاتصال بـ Gemini
if ($response === false) {
    http_response_code(502); // Bad Gateway
    echo json_encode(['error' => 'تعذّر الاتصال بخادم Gemini: ' . $curlError]);
    exit;
}

$geminiData = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    $msg = $geminiData['error']['message'] ?? 'خطأ غير معروف من Gemini API';
    echo json_encode(['error' => 'فشل طلب Gemini: ' . $msg]);
    exit;
}

// 7) استخراج نص الرد من استجابة Gemini
$replyText = $geminiData['candidates'][0]['content']['parts'][0]['text'] ?? null;

if ($replyText === null) {
    http_response_code(502);
    echo json_encode(['error' => 'لم يصل رد نصي من Gemini']);
    exit;
}

// 8) إعادة الرد إلى app.js بالشكل الذي يتوقعه: { reply: "..." }
echo json_encode(['reply' => $replyText], JSON_UNESCAPED_UNICODE);
