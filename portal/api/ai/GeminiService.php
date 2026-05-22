<?php
// portal/services/ai/GeminiService.php
declare(strict_types=1);

class GeminiService {
    private string $apiKey;
    private string $model;
    private string $systemPrompt;
    private array $tools = [];

    public function __construct(string $apiKey, string $model = 'gemini-2.5-flash') {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->systemPrompt = <<<PROMPT
คุณคือ AI ผู้ช่วยวิเคราะห์ข้อมูลของระบบ **RSU Medical Clinic** ตอบเป็นภาษาไทยสุภาพและเป็นมืออาชีพ

# ขอบเขตข้อมูลที่คุณเข้าถึงได้ (ผ่าน function calling — เรียก tool ตามที่ผู้ใช้ถาม)
- 🎯 **แคมเปญและการจอง**: get_system_overview / get_all_campaigns / get_booking_trend / get_cancellation_analysis
- 💰 **การเงิน (Cash Book)**: get_finance_summary / get_finance_top_categories / get_finance_recent_transactions — รองรับช่วงเวลา today, this_month, last_month, this_year ฯลฯ
- 👩‍⚕️ **ตารางแพทย์**: get_doctor_schedule_today (ดูวันนี้/ระบุวันที่) · get_doctor_schedule_week (สรุปสัปดาห์)
- 📦 **คลังพัสดุ**: get_low_stock_consumables (วัสดุใกล้หมด) · get_asset_summary (สรุปครุภัณฑ์ + ประกัน)
- 👥 **ผู้ใช้งาน**: get_user_stats (รวม LINE binding + active 30 วัน) · get_recent_activities (audit log)
- 🛠️ **ระบบ**: get_recent_errors (error logs) · get_module_overview (ภาพรวมทุกโมดูล)

# วิธีตอบ
1. **เลือก tool ที่เกี่ยวข้องและเรียกใช้เสมอ** ก่อนตอบคำถามเชิงข้อมูล — อย่าตอบจากความรู้ทั่วไป
2. ถ้าคำถามครอบหลายโมดูล → เรียกหลาย tool ตามลำดับ
3. ถ้า tool return error เพราะ table ไม่มี → แจ้งผู้ใช้ว่าโมดูลนั้นยังไม่ได้ติดตั้ง แทนที่จะเดา
4. นำเสนอข้อมูลเป็น bullet / ตาราง / สรุปสั้น ๆ + ตัวเลขที่สำคัญ
5. ถ้าเหมาะสมให้ **คำแนะนำเชิงปฏิบัติ** (เช่น "ควรสั่งซื้อวัสดุ A เพิ่ม" / "แคมเปญ B มีอัตราเติมต่ำ ลองโปรโมท")
6. **อย่าเปิดเผยข้อมูลส่วนตัว** ของผู้ป่วย/ผู้ใช้ราย ๆ — ใช้ aggregate/count เท่านั้น
7. ตัวเลขเงินใส่ comma + คำว่า "บาท" (เช่น 12,345 บาท)
PROMPT;
    }

    public function setSystemPrompt(string $prompt): void {
        $this->systemPrompt = $prompt;
    }

    public function setTools(array $tools): void {
        $this->tools = $tools;
    }

    /**
     * Call Gemini API to generate content
     */
    public function generate(array $contents): array {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
        
        $body = [
            'system_instruction' => ['parts' => [['text' => $this->systemPrompt]]],
            'contents'           => $contents,
            'generationConfig'   => [
                'temperature' => 0.3,
                'maxOutputTokens' => 2048,
                'topP' => 0.8,
                'topK' => 40
            ]
        ];

        if (!empty($this->tools)) {
            $body['tools'] = $this->tools;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = $decoded['error']['message'] ?? "HTTP Error {$httpCode}";
            throw new Exception("Gemini API Error: " . $msg);
        }

        return $decoded;
    }

    /**
     * Helper to discover available models and pick the best one
     */
    public static function autoPickModel(string $apiKey): string {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($res ?: '{}', true);
        $best = 'gemini-2.5-flash'; // Fallback
        
        $candidates = [];
        foreach ($data['models'] ?? [] as $m) {
            $name = str_replace('models/', '', $m['name']);
            if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
                continue;
            }
            if (strpos($name, 'gemini') !== false && !preg_match('/vision|embed|aqa/i', $name)) {
                $candidates[] = $name;
            }
        }
        
        if (!empty($candidates)) {
            // Prefer current Flash models for speed and availability.
            usort($candidates, function($a, $b) {
                $score = static function (string $name): int {
                    $score = 0;
                    if (preg_match('/gemini-(\d+)\.(\d+)/i', $name, $m)) {
                        $score += (int)$m[1] * 100 + (int)$m[2] * 10;
                    }
                    if (stripos($name, 'flash') !== false) $score += 5;
                    if (stripos($name, 'preview') !== false) $score -= 1;
                    if (stripos($name, '-exp') !== false) $score -= 2;
                    return $score;
                };
                return $score($b) <=> $score($a);
            });
            $best = $candidates[0];
        }
        
        return $best;
    }
}
