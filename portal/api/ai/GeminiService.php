<?php
// portal/services/ai/GeminiService.php
declare(strict_types=1);

class GeminiService {
    private string $apiKey;
    private string $model;
    private string $systemPrompt;
    private array $tools = [];

    public function __construct(string $apiKey, string $model = 'gemini-2.0-flash') {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->systemPrompt = "คุณคือ AI ผู้เชี่ยวชาญด้านการวิเคราะห์ข้อมูลประจำระบบ RSU Medical Clinic ตอบเป็นภาษาไทยด้วยความสุภาพและเป็นมืออาชีพ";
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
        $best = 'gemini-1.5-flash'; // Fallback
        
        $candidates = [];
        foreach ($data['models'] ?? [] as $m) {
            $name = str_replace('models/', '', $m['name']);
            if (strpos($name, 'gemini') !== false && !preg_match('/vision|embed|aqa/i', $name)) {
                $candidates[] = $name;
            }
        }
        
        if (!empty($candidates)) {
            // Prefer 2.0 > 1.5, Flash > Pro for speed
            usort($candidates, function($a, $b) {
                return strpos($b, '2.0') <=> strpos($a, '2.0') ?: strpos($b, 'flash') <=> strpos($a, 'flash');
            });
            $best = $candidates[0];
        }
        
        return $best;
    }
}
