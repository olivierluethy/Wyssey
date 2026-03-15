<?php

// ─────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────

$OPENAI_API_KEY = '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';

// erlaubte Domains
$allowed_origins = [
    'https://wyssey.com',
    'http://localhost:5500',
    'http://127.0.0.1:5500'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// ─────────────────────────────────────────────
// CORS HEADERS
// ─────────────────────────────────────────────

if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─────────────────────────────────────────────
// METHOD CHECK
// ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "error" => "Method Not Allowed"
    ]);
    exit;
}

// ─────────────────────────────────────────────
// INPUT
// ─────────────────────────────────────────────

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input["messages"])) {
    http_response_code(400);
    echo json_encode([
        "error" => "Invalid request. 'messages' missing."
    ]);
    exit;
}

// ─────────────────────────────────────────────
// OPENAI REQUEST
// ─────────────────────────────────────────────

$payload = [
    "model" => "gpt-4o-mini",
    "input" => $input["messages"],
    "temperature" => $input["temperature"] ?? 0.9,
    "max_output_tokens" => $input["max_tokens"] ?? 800
];

$ch = curl_init("https://api.openai.com/v1/responses");

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $OPENAI_API_KEY
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 90
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Curl error: " . curl_error($ch)
    ]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// ─────────────────────────────────────────────
// ERROR HANDLING
// ─────────────────────────────────────────────

if ($http_code !== 200) {
    http_response_code($http_code);
    echo $response;
    exit;
}

$data = json_decode($response, true);

// Text aus der Response extrahieren
$content = $data["output"][0]["content"][0]["text"] ?? null;

if (!$content) {
    http_response_code(502);
    echo json_encode([
        "error" => "Invalid response from OpenAI"
    ]);
    exit;
}

// ─────────────────────────────────────────────
// SUCCESS
// ─────────────────────────────────────────────

echo json_encode([
    "content" => $content
]);
