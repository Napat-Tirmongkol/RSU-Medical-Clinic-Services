<?php
// portal/api/ai/ajax_ai.php — AI Service Endpoint
declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../includes/auth.php'; // Reuse existing portal auth
require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/DataAssistant.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

// Simple CSRF check if the function exists
if (function_exists('validate_csrf_or_die')) {
    try {
        validate_csrf_or_die();
    } catch (Exception $e) {
        exit(json_encode(['ok' => false, 'error' => 'Invalid security token']));
    }
}

$query = trim($_POST['query'] ?? '');
if (!$query) {
    echo json_encode(['ok' => false, 'error' => 'กรุณาพิมพ์คำถาม']);
    exit;
}

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey) {
    echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า API Key']);
    exit;
}

try {
    $pdo = db();
    $assistant = new DataAssistant($pdo);
    
    // Pick model (cached in session for performance)
    if (empty($_SESSION['_gemini_model'])) {
        $_SESSION['_gemini_model'] = GeminiService::autoPickModel($apiKey);
    }
    
    $ai = new GeminiService($apiKey, $_SESSION['_gemini_model']);
    $ai->setTools($assistant->getToolDefinitions());
    
    $contents = [['role' => 'user', 'parts' => [['text' => $query]]]];
    $finalText = '';
    $maxIter = 4;

    for ($i = 0; $i < $maxIter; $i++) {
        $response = $ai->generate($contents);
        
        $candidate = $response['candidates'][0] ?? null;
        if (!$candidate) break;
        
        $parts = $candidate['content']['parts'] ?? [];
        $role  = $candidate['content']['role'] ?? 'model';
        
        // Normalize parts for potential tool calls (Gemini expects objects for args)
        $normalizedParts = array_map(function($p) {
            if (isset($p['functionCall'])) {
                $p['functionCall']['args'] = (object)($p['functionCall']['args'] ?? []);
            }
            return $p;
        }, $parts);
        
        $contents[] = ['role' => $role, 'parts' => $normalizedParts];
        
        $funcCalls = array_filter($normalizedParts, fn($p) => isset($p['functionCall']));
        $textParts = array_filter($normalizedParts, fn($p) => isset($p['text']));
        
        if (empty($funcCalls)) {
            foreach ($textParts as $p) $finalText .= $p['text'];
            break;
        }
        
        // Execute tool calls
        $funcResponses = [];
        foreach ($funcCalls as $p) {
            $fc = $p['functionCall'];
            $name = $fc['name'];
            $args = (array)($fc['args'] ?? []);
            
            $data = $assistant->executeTool($name, $args);
            
            $funcResponses[] = [
                'functionResponse' => [
                    'name' => $name,
                    'response' => ['result' => $data]
                ]
            ];
        }
        
        $contents[] = ['role' => 'user', 'parts' => $funcResponses];
    }

    if (!$finalText) {
        throw new Exception("AI ไม่สามารถส่งคำตอบได้ในขณะนี้");
    }

    echo json_encode([
        'ok' => true,
        'reply' => $finalText
    ]);

} catch (Exception $e) {
    error_log("AI Service Error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
