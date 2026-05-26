<?php
/**
 * Morning Brief — Gemini narrator service.
 *
 * รับข้อมูล snapshot จาก morning_brief_collect_all() แล้วเรียก Gemini 2.5 Flash
 * ขอให้สรุปเป็น narrative ภาษาไทย พร้อม urgency_level + action_items.
 *
 * Return format (parsed JSON):
 *   { ok: bool, narrative: string, urgency: 'low'|'normal'|'high'|'critical',
 *     priorities: [{title, detail, module, link?}], model: string, error?: string }
 *
 * ถ้า Gemini ใช้ไม่ได้ → คืน fallback narrative ที่ build จาก data ตรงๆ (rule-based)
 */
declare(strict_types=1);

function morning_brief_generate_narrative(array $data, ?string $apiKey = null): array {
    if (!$apiKey) {
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if (!$apiKey && file_exists(__DIR__ . '/../../config/secrets.php')) {
            $s = include __DIR__ . '/../../config/secrets.php';
            $apiKey = $s['GEMINI_API_KEY'] ?? '';
        }
    }
    if (!$apiKey) {
        return _mb_fallback($data, 'ยังไม่ได้ตั้งค่า Gemini API key');
    }

    $prompt = _mb_build_prompt($data);
    $body = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0.35,
            'maxOutputTokens' => 1400,
            'responseMimeType' => 'application/json',
        ],
    ];

    $models = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $raw = ''; $httpCode = 0;
    foreach ($models as $model) {
        $resp = _mb_call_gemini($apiKey, $model, $body);
        if ($resp['curlErr']) {
            return _mb_fallback($data, 'เชื่อมต่อ Gemini ไม่ได้: ' . $resp['curlErr']);
        }
        $raw = $resp['raw']; $httpCode = $resp['httpCode'];
        if ($httpCode === 200) {
            $j = json_decode($raw, true);
            $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = _mb_parse_response($text);
            if ($parsed) {
                $parsed['model'] = $model;
                $parsed['ok'] = true;
                return $parsed;
            }
            return _mb_fallback($data, 'AI ตอบกลับในรูปแบบที่อ่านไม่ได้');
        }
        if ($httpCode !== 404) break;
    }
    $errMsg = json_decode($raw, true)['error']['message'] ?? "HTTP $httpCode";
    return _mb_fallback($data, "Gemini: $errMsg");
}

function _mb_build_prompt(array $data): string {
    $clinic = $data['clinic'] ?? [];
    $sch    = $data['scholarship'] ?? [];
    $fin    = $data['finance'] ?? [];
    $edms   = $data['edms'] ?? [];
    $inv    = $data['inventory'] ?? [];

    $summary = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return <<<PROMPT
คุณคือผู้ช่วย admin ของคลินิกแพทย์ มหาวิทยาลัยรังสิต กำลังเขียน "Morning Brief" สำหรับเช้านี้

ข้อมูล snapshot ของวันนี้ ({$clinic['date_thai']} วัน{$clinic['weekday_thai']}):
```json
$summary
```

กรุณาสรุปเป็น JSON object เดียว ภาษาไทย เป็นมิตร ตรงประเด็น ไม่ทักทาย ไม่ใส่ emoji:

{
  "narrative": "สรุปภาพรวมเช้านี้ 3-5 ประโยค ที่ admin ควรรู้ก่อนเริ่มงาน — เน้นของที่ต้องทำ ไม่ลิสต์ตัวเลขซ้ำ",
  "urgency": "low|normal|high|critical (ดูจากจำนวนของค้างและความเร่งด่วน)",
  "priorities": [
    {
      "title": "ชื่อสั้นไม่เกิน 30 ตัวอักษร",
      "detail": "รายละเอียด 1-2 ประโยค ว่าต้องทำอะไร",
      "module": "scholarship|finance|edms|inventory|clinic|other"
    }
    // 3-5 รายการ เรียงตามความเร่งด่วน
  ]
}

กฎ:
- ถ้า pending_approvals > 0 → ใส่ใน priorities เป็นอันดับแรกๆ
- ถ้า sla_breached > 0 → urgency อย่างน้อย "high"
- ถ้า consumables_low_stock > 0 → ใส่ priority หมวด inventory
- ถ้า recurring_due_today > 0 → priority หมวด finance (ไป generate)
- ถ้าทุกอย่างปกติ → urgency = "low" + narrative สั้นๆ ว่าเช้านี้สงบ
- ห้ามใส่ข้อมูลที่ไม่มีใน snapshot · ห้ามแต่งตัวเลขใหม่
- ตอบเป็น JSON object เดียวเท่านั้น ไม่ต้องห่อ markdown
PROMPT;
}

function _mb_call_gemini(string $apiKey, string $model, array $body): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return ['raw' => $raw ?: '', 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

function _mb_parse_response(string $text): ?array {
    $text = trim($text);
    if (preg_match('/```json\s*(.+?)\s*```/s', $text, $m)) $text = $m[1];
    elseif (preg_match('/```\s*(.+?)\s*```/s', $text, $m)) $text = $m[1];
    $j = json_decode($text, true);
    if (!is_array($j)) return null;
    $urgency = strtolower($j['urgency'] ?? 'normal');
    if (!in_array($urgency, ['low','normal','high','critical'], true)) $urgency = 'normal';
    return [
        'narrative' => trim((string)($j['narrative'] ?? '')),
        'urgency' => $urgency,
        'priorities' => is_array($j['priorities'] ?? null) ? array_slice($j['priorities'], 0, 6) : [],
    ];
}

// Rule-based fallback ─ ใช้เมื่อ Gemini ไม่ตอบ/ไม่ได้ตั้ง key
function _mb_fallback(array $data, string $reason = ''): array {
    $clinic = $data['clinic'] ?? [];
    $sch    = $data['scholarship'] ?? [];
    $fin    = $data['finance'] ?? [];
    $edms   = $data['edms'] ?? [];
    $inv    = $data['inventory'] ?? [];

    $priorities = [];
    if (($sch['pending_approvals'] ?? 0) > 0) {
        $priorities[] = [
            'title' => 'อนุมัติ clock-in นักศึกษาทุน',
            'detail' => 'มี ' . $sch['pending_approvals'] . ' รายการรอดูในหน้านักศึกษาทุน',
            'module' => 'scholarship',
        ];
    }
    if (($edms['sla_breached'] ?? 0) > 0) {
        $priorities[] = [
            'title' => 'งานสารบรรณเกิน SLA',
            'detail' => 'มี ' . $edms['sla_breached'] . ' งานที่ overdue ต้องเคลียร์',
            'module' => 'edms',
        ];
    }
    if (($edms['tasks_due_today'] ?? 0) > 0) {
        $priorities[] = [
            'title' => 'งานที่ครบกำหนดวันนี้',
            'detail' => 'มี ' . $edms['tasks_due_today'] . ' งานต้องจัดการก่อนสิ้นวัน',
            'module' => 'edms',
        ];
    }
    if (($fin['recurring_due_today'] ?? 0) > 0) {
        $priorities[] = [
            'title' => 'รายการประจำเดือนต้อง generate',
            'detail' => 'Cash Book มี ' . $fin['recurring_due_today'] . ' รายการประจำที่ถึงรอบ',
            'module' => 'finance',
        ];
    }
    if (($inv['consumables_low_stock'] ?? 0) > 0) {
        $priorities[] = [
            'title' => 'วัสดุใกล้หมด',
            'detail' => 'มีของในคลังต่ำกว่าจุดสั่งซื้อ ' . $inv['consumables_low_stock'] . ' รายการ',
            'module' => 'inventory',
        ];
    }
    if (($sch['payouts_pending_count'] ?? 0) > 0) {
        $priorities[] = [
            'title' => 'ค่าตอบแทนนักศึกษาทุนค้างจ่าย',
            'detail' => $sch['payouts_pending_count'] . ' รายการ ยอดรวม ' .
                number_format((float)($sch['payouts_pending_total'] ?? 0), 2) . ' บาท',
            'module' => 'scholarship',
        ];
    }

    $urgency = 'low';
    if (($edms['sla_breached'] ?? 0) > 0 || ($edms['tasks_overdue'] ?? 0) > 3) $urgency = 'critical';
    elseif (count($priorities) >= 4) $urgency = 'high';
    elseif (count($priorities) >= 1) $urgency = 'normal';

    if ($priorities) {
        $narrative = 'เช้านี้มี ' . count($priorities) . ' เรื่องที่ต้องดู — ' .
                     ($priorities[0]['title'] ?? '') .
                     (count($priorities) > 1 ? ' และอีก ' . (count($priorities) - 1) . ' รายการ' : '');
    } else {
        $narrative = 'เช้านี้ทุกอย่างดูสงบ ไม่มีเรื่องค้างที่ต้องทำด่วน เริ่มวันด้วยความสบายใจได้เลย';
    }

    return [
        'ok' => false,
        'narrative' => $narrative,
        'urgency' => $urgency,
        'priorities' => $priorities,
        'model' => 'fallback',
        'error' => $reason,
    ];
}
