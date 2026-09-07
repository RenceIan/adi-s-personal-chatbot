<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST requests only.']);
    exit;
}

$localConfig = __DIR__ . '/config.php';
$config = is_file($localConfig) ? require $localConfig : [];
$geminiKey = $config['gemini_api_key'] ?? getenv('GEMINI_API_KEY');
$openAIKey = $config['openai_api_key'] ?? getenv('OPENAI_API_KEY');
$githubToken = $config['github_token'] ?? getenv('GITHUB_TOKEN');
$token = $geminiKey ?: ($openAIKey ?: $githubToken);
if (!$token) {
    http_response_code(503);
    echo json_encode(['error' => 'No AI provider key is configured in PHP.']);
    exit;
}

$attachment = null;
if (isset($_FILES['attachment'])) {
    $upload = $_FILES['attachment'];
    if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Attachments must be smaller than 10 MB.']);
        exit;
    }
    $allowedTypes = ['application/pdf', 'text/plain', 'text/html', 'text/css', 'application/json', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $fileType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if (!in_array($fileType, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'This file type is not supported. Use an image, PDF, or text file.']);
        exit;
    }
    $attachment = [
        'mime_type' => $fileType,
        'data' => base64_encode(file_get_contents($upload['tmp_name']))
    ];
    $input = $_POST;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
}
$messages = json_decode($input['messages'] ?? '[]', true);
if (!is_array($messages) || count($messages) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A messages array is required.']);
    exit;
}

$systemPrompt = "You are Adi's Personal AI, a practical personal copilot. Be concise, helpful, and honest about what you can do. When the user asks for a task, help them break it into clear next steps.";
$recentMessages = array_slice($messages, -12);

if ($geminiKey) {
    $contents = array_map(function ($message) {
        $content = [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message['content']]]
        ];
        return $content;
    }, $recentMessages);
    if ($attachment) {
        $lastIndex = count($contents) - 1;
        $contents[$lastIndex]['parts'][] = ['inlineData' => [
            'mimeType' => $attachment['mime_type'],
            'data' => $attachment['data']
        ]];
    }
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 700]
    ]);
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . urlencode($geminiKey);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
} else {
    if ($attachment) {
        http_response_code(400);
        echo json_encode(['error' => 'Attachments currently require the Gemini provider.']);
        exit;
    }
    $payload = json_encode([
        'model' => $openAIKey ? 'gpt-4o-mini' : 'openai/gpt-4o-mini',
        'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $recentMessages),
        'temperature' => 0.7,
        'max_tokens' => 700
    ]);
    $endpoint = $openAIKey ? 'https://api.openai.com/v1/chat/completions' : 'https://models.github.ai/inference/chat/completions';
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Accept: application/json'];
}

$curl = curl_init($endpoint);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12
]);
$response = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'AI provider request failed: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);
$reply = $geminiKey
    ? ($data['candidates'][0]['content']['parts'][0]['text'] ?? null)
    : ($data['choices'][0]['message']['content'] ?? null);
if ($status < 200 || $status >= 300 || !$reply) {
    http_response_code(502);
    echo json_encode(['error' => $data['error']['message'] ?? $data['error']['status'] ?? 'AI provider returned an unexpected response.']);
    exit;
}

echo json_encode(['reply' => $reply]);
