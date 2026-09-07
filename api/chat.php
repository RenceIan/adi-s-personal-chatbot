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
$token = $config['github_token'] ?? getenv('GITHUB_TOKEN');
if (!$token) {
    http_response_code(503);
    echo json_encode(['error' => 'GITHUB_TOKEN is not configured in PHP.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$messages = $input['messages'] ?? [];
if (!is_array($messages) || count($messages) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A messages array is required.']);
    exit;
}

$payload = json_encode([
    'model' => 'openai/gpt-4o-mini',
    'messages' => array_merge([
        [
            'role' => 'system',
            'content' => "You are Adi's Personal AI, a practical personal copilot. Be concise, helpful, and honest about what you can do. When the user asks for a task, help them break it into clear next steps."
        ]
    ], array_slice($messages, -12)),
    'temperature' => 0.7,
    'max_tokens' => 700
]);

$curl = curl_init('https://models.github.ai/inference/chat/completions');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 30
]);
$response = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'GitHub Models request failed: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);
if ($status < 200 || $status >= 300 || !isset($data['choices'][0]['message']['content'])) {
    http_response_code(502);
    echo json_encode(['error' => $data['error']['message'] ?? 'GitHub Models returned an unexpected response.']);
    exit;
}

echo json_encode(['reply' => $data['choices'][0]['message']['content']]);
