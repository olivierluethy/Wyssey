<?php

// ─────────────────────────────────────────────
// 1-on-1 chat proxy. Delegates the OpenAI call to openai.php.
// ─────────────────────────────────────────────

require_once __DIR__ . '/openai.php';

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

try {
    $content = wyssey_openai_chat($input["messages"], [
        "temperature" => $input["temperature"] ?? 0.9,
        // Keep replies short & conversational (issue #2). Callers may still
        // override, but the default is intentionally small.
        "max_tokens"  => $input["max_tokens"] ?? 300,
    ]);
} catch (Exception $e) {
    http_response_code(502);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
    exit;
}

// ─────────────────────────────────────────────
// SUCCESS
// ─────────────────────────────────────────────

echo json_encode([
    "content" => $content
]);
