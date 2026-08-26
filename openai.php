<?php
// ─────────────────────────────────────────────
// Shared OpenAI helper used by api.php (1-on-1) and room.php (groups).
// Keeps a single OpenAI call path so both endpoints behave identically.
// ─────────────────────────────────────────────

// The API key lives in one place. Prefer an environment variable so the
// secret does not have to sit in the source file; fall back to the constant.
if (!defined('WYSSEY_OPENAI_API_KEY')) {
    define('WYSSEY_OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?:
        '__REDACTED_OPENAI_KEY__');
}

/**
 * Call OpenAI with a list of chat messages and return the assistant's text.
 *
 * @param array $messages Array of { role, content } messages.
 * @param array $opts     Optional: temperature, max_tokens.
 * @return string         The assistant reply text.
 * @throws Exception      On transport or API errors.
 */
function wyssey_openai_chat(array $messages, array $opts = []): string {
    // Test hook: when WYSSEY_FAKE_AI is set, echo a deterministic reply
    // instead of calling the network. Used by the CLI test harness.
    if (getenv('WYSSEY_FAKE_AI')) {
        $last = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'system') {
                $last = is_string($m['content']) ? $m['content'] : '';
            }
        }
        return '[fake-ai reply to: ' . mb_substr(trim($last), 0, 60) . ']';
    }

    $payload = [
        'model'             => 'gpt-4o-mini',
        'input'             => $messages,
        'temperature'       => $opts['temperature'] ?? 0.9,
        'max_output_tokens' => $opts['max_tokens'] ?? 300,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WYSSEY_OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 90,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Curl error: ' . $err);
    }
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception('OpenAI error (' . $http_code . '): ' . $response);
    }

    $data = json_decode($response, true);

    // The /responses API nests the text under output[].content[].text.
    $content = $data['output'][0]['content'][0]['text'] ?? null;

    // Some responses put a status message first; scan for the first text part.
    if ($content === null && isset($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $item) {
            if (isset($item['content'][0]['text'])) {
                $content = $item['content'][0]['text'];
                break;
            }
        }
    }

    if (!$content) {
        throw new Exception('Invalid response from OpenAI');
    }

    return $content;
}
