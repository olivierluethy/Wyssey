<?php
// ─────────────────────────────────────────────
// Group Rooms backend (issues #1 + #3).
//
// A room is a shared multi-participant conversation stored as a JSON file in
// rooms/<CODE>.json. Humans join by code; AI characters debate round-robin.
// Clients poll `state` for new messages. No websockets required.
// ─────────────────────────────────────────────

require_once __DIR__ . '/openai.php';

// ── CORS ──────────────────────────────────────
$allowed_origins = [
    'https://wyssey.com',
    'http://localhost:5500',
    'http://127.0.0.1:5500',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Config ────────────────────────────────────
const ROOMS_DIR         = __DIR__ . '/rooms';
const MAX_AIS           = 6;
const MAX_MSG_LEN       = 2000;
const MAX_STORED_MSGS   = 200;   // keep the room file bounded
const HISTORY_FOR_AI    = 24;    // messages of context sent to each AI
const AI_LOCK_TTL       = 60;    // seconds before a stale AI round can be reclaimed

// ── Helpers ───────────────────────────────────

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function read_input(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    // action may arrive via query string or JSON body
    $action = $_GET['action'] ?? $body['action'] ?? '';
    $body['action'] = $action;
    return $body;
}

function ensure_rooms_dir(): void {
    if (!is_dir(ROOMS_DIR)) {
        if (!@mkdir(ROOMS_DIR, 0770, true) && !is_dir(ROOMS_DIR)) {
            fail(500, 'Room storage is not available (cannot create rooms directory).');
        }
    }
    if (!is_writable(ROOMS_DIR)) {
        fail(500, 'Room storage is not writable.');
    }
}

function room_path(string $code): string {
    return ROOMS_DIR . '/' . $code . '.json';
}

function gen_code(): string {
    // 6 chars, no ambiguous 0/O/1/I/L to keep codes easy to share verbally.
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function gen_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(5));
}

/**
 * Open a room file with an exclusive lock, run $fn($room) which may mutate and
 * return the (possibly modified) room, persist it, and return $fn's second
 * value. $fn returns [ $roomToSave, $result ]. If $roomToSave is null, nothing
 * is written (read-only access).
 */
function with_room(string $code, callable $fn) {
    $path = room_path($code);
    if (!is_file($path)) {
        fail(404, 'Room not found.');
    }
    $fh = fopen($path, 'c+');
    if (!$fh) fail(500, 'Cannot open room.');
    try {
        if (!flock($fh, LOCK_EX)) fail(500, 'Cannot lock room.');
        $raw  = stream_get_contents($fh);
        $room = json_decode($raw, true);
        if (!is_array($room)) fail(500, 'Room file is corrupt.');

        [$save, $result] = $fn($room);

        if ($save !== null) {
            $json = json_encode($save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
        }
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Public-facing view of a room (personality text is stripped out). */
function public_state(array $room, int $since = 0): array {
    $participants = array_map(function ($p) {
        return [
            'id'          => $p['id'],
            'name'        => $p['name'],
            'type'        => $p['type'],
            'characterId' => $p['characterId'] ?? null,
            'image'       => $p['image'] ?? null,
        ];
    }, $room['participants']);

    $messages = array_values(array_filter($room['messages'], fn($m) => $m['seq'] > $since));

    return [
        'code'         => $room['code'],
        'name'         => $room['name'],
        'participants' => $participants,
        'messages'     => $messages,
        'seq'          => $room['seq'],
        'aiRunning'    => !empty($room['aiRunning']) && (time() - $room['aiRunning'] < AI_LOCK_TTL),
    ];
}

function append_message(array &$room, string $senderId, string $senderName, string $senderType, string $content): void {
    $room['seq']++;
    $room['messages'][] = [
        'seq'        => $room['seq'],
        'senderId'   => $senderId,
        'senderName' => $senderName,
        'senderType' => $senderType,
        'content'    => $content,
        'ts'         => time(),
    ];
    if (count($room['messages']) > MAX_STORED_MSGS) {
        $room['messages'] = array_slice($room['messages'], -MAX_STORED_MSGS);
    }
}

/** Build the OpenAI message list for one AI participant's turn. */
function build_ai_messages(array $room, array $ai): array {
    $others = [];
    foreach ($room['participants'] as $p) {
        if ($p['id'] !== $ai['id']) $others[] = $p['name'];
    }
    $othersList = $others ? implode(', ', $others) : 'the user';

    $system = "You are {$ai['name']}. {$ai['personality']}\n\n"
        . "You are in a live group conversation with: {$othersList}. "
        . "This is a debate/discussion, so react directly to what the others just said — "
        . "agree, push back, or build on their points, and address people by name when it helps. "
        . "Speak only as {$ai['name']}; never write other people's lines.\n\n"
        . "IMPORTANT: Keep it short and conversational — usually 2 to 4 sentences. "
        . "Do not give long monologues. Only go longer if someone explicitly asks you to elaborate. "
        . "Stay in character and never say you are an AI unless asked directly.";

    $messages = [['role' => 'system', 'content' => $system]];

    $recent = array_slice($room['messages'], -HISTORY_FOR_AI);
    foreach ($recent as $m) {
        if ($m['senderId'] === $ai['id']) {
            $messages[] = ['role' => 'assistant', 'content' => $m['content']];
        } else {
            // Prefix with the speaker so the model can tell participants apart.
            $messages[] = ['role' => 'user', 'content' => $m['senderName'] . ': ' . $m['content']];
        }
    }
    return $messages;
}

/**
 * Try to claim the AI round for this room. Returns true if claimed. Must be
 * called on a fresh load under lock. Sets aiRunning if free or stale.
 */
function claim_ai_round(array &$room): bool {
    $running = $room['aiRunning'] ?? 0;
    if ($running && (time() - $running < AI_LOCK_TTL)) {
        return false; // someone else is already running the round
    }
    $room['aiRunning'] = time();
    return true;
}

/**
 * Run one round-robin round: each AI, in order, replies once, seeing every
 * message appended so far (including earlier AIs in this same round). The lock
 * is only held for the quick load/append around each slow OpenAI call.
 */
function run_ai_round(string $code): void {
    // Snapshot the AI list under lock.
    $ais = with_room($code, function ($room) {
        $ais = array_values(array_filter($room['participants'], fn($p) => $p['type'] === 'ai'));
        return [null, $ais];
    });

    try {
        foreach ($ais as $ai) {
            // Rebuild transcript fresh so this AI sees earlier AIs' new lines.
            $messages = with_room($code, fn($room) => [null, build_ai_messages($room, $ai)]);
            try {
                $reply = wyssey_openai_chat($messages, ['max_tokens' => 300, 'temperature' => 0.9]);
            } catch (Exception $e) {
                $reply = null; // skip this AI's turn on error; round continues
            }
            if ($reply !== null && trim($reply) !== '') {
                with_room($code, function ($room) use ($ai, $reply) {
                    append_message($room, $ai['id'], $ai['name'], 'ai', trim($reply));
                    return [$room, null];
                });
            }
        }
    } finally {
        // Always clear the round lock, even on fatal error inside the loop.
        with_room($code, function ($room) {
            $room['aiRunning'] = 0;
            return [$room, null];
        });
    }
}

// ── Router ────────────────────────────────────

ensure_rooms_dir();
$in = read_input();
$action = $in['action'];

switch ($action) {

case 'create': {
    $roomName    = trim((string)($in['roomName'] ?? 'Group Room'));
    $displayName = trim((string)($in['displayName'] ?? ''));
    $ais         = $in['ais'] ?? [];

    if ($displayName === '') fail(400, 'displayName is required.');
    if (!is_array($ais) || count($ais) < 1) fail(400, 'Pick at least one AI character.');
    if (count($ais) > MAX_AIS) fail(400, 'Too many AI characters (max ' . MAX_AIS . ').');

    $roomName = $roomName !== '' ? mb_substr($roomName, 0, 80) : 'Group Room';

    $participants = [[
        'id'   => gen_id('p'),
        'name' => mb_substr($displayName, 0, 40),
        'type' => 'human',
    ]];
    $creatorId = $participants[0]['id'];

    $seen = [];
    foreach ($ais as $ai) {
        $cid = (string)($ai['id'] ?? '');
        $name = trim((string)($ai['name'] ?? ''));
        $personality = trim((string)($ai['personality'] ?? ''));
        if ($cid === '' || $name === '' || $personality === '') continue;
        if (isset($seen[$cid])) continue; // no duplicate characters
        $seen[$cid] = true;
        $participants[] = [
            'id'          => 'ai_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $cid),
            'name'        => mb_substr($name, 0, 40),
            'type'        => 'ai',
            'characterId' => $cid,
            'image'       => (string)($ai['image'] ?? '') ?: null,
            'personality' => mb_substr($personality, 0, 4000),
        ];
    }
    if (count($participants) < 2) fail(400, 'No valid AI characters provided.');

    // Unique code.
    $code = '';
    for ($tries = 0; $tries < 8; $tries++) {
        $candidate = gen_code();
        if (!is_file(room_path($candidate))) { $code = $candidate; break; }
    }
    if ($code === '') fail(500, 'Could not allocate a room code.');

    $room = [
        'code'         => $code,
        'name'         => $roomName,
        'createdAt'    => time(),
        'aiRunning'    => 0,
        'seq'          => 0,
        'participants' => $participants,
        'messages'     => [],
    ];
    // Seed a system welcome line naming the participants.
    $names = implode(', ', array_map(fn($p) => $p['name'], $participants));
    append_message($room, 'system', 'System', 'system', "Group room \"$roomName\" created. Participants: $names. Ask a question to start the debate.");

    $json = json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents(room_path($code), $json, LOCK_EX) === false) {
        fail(500, 'Could not create room file.');
    }

    echo json_encode([
        'code'          => $code,
        'participantId' => $creatorId,
        'state'         => public_state($room),
    ]);
    break;
}

case 'join': {
    $code        = strtoupper(trim((string)($in['code'] ?? '')));
    $displayName = trim((string)($in['displayName'] ?? ''));
    if ($code === '') fail(400, 'code is required.');
    if ($displayName === '') fail(400, 'displayName is required.');

    $result = with_room($code, function ($room) use ($displayName) {
        $participant = [
            'id'   => gen_id('p'),
            'name' => mb_substr($displayName, 0, 40),
            'type' => 'human',
        ];
        $room['participants'][] = $participant;
        append_message($room, 'system', 'System', 'system', "{$participant['name']} joined the room.");
        return [$room, ['participantId' => $participant['id'], 'state' => public_state($room)]];
    });

    echo json_encode($result);
    break;
}

case 'state': {
    $code  = strtoupper(trim((string)($in['code'] ?? $_GET['code'] ?? '')));
    $since = (int)($in['since'] ?? $_GET['since'] ?? 0);
    if ($code === '') fail(400, 'code is required.');

    $state = with_room($code, fn($room) => [null, public_state($room, $since)]);
    echo json_encode(['state' => $state]);
    break;
}

case 'post': {
    $code          = strtoupper(trim((string)($in['code'] ?? '')));
    $participantId = trim((string)($in['participantId'] ?? ''));
    $content       = trim((string)($in['content'] ?? ''));
    if ($code === '') fail(400, 'code is required.');
    if ($participantId === '') fail(400, 'participantId is required.');
    if ($content === '') fail(400, 'content is required.');
    if (mb_strlen($content) > MAX_MSG_LEN) $content = mb_substr($content, 0, MAX_MSG_LEN);

    // Append the human message and try to claim the AI round in one locked step.
    $claimed = with_room($code, function ($room) use ($participantId, $content) {
        $sender = null;
        foreach ($room['participants'] as $p) {
            if ($p['id'] === $participantId) { $sender = $p; break; }
        }
        if (!$sender || $sender['type'] !== 'human') fail(403, 'Unknown participant.');

        append_message($room, $sender['id'], $sender['name'], 'human', $content);
        $claimed = claim_ai_round($room);
        return [$room, $claimed];
    });

    if ($claimed) {
        run_ai_round($code);
    }

    $state = with_room($code, fn($room) => [null, public_state($room)]);
    echo json_encode(['state' => $state]);
    break;
}

case 'continue': {
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    if ($code === '') fail(400, 'code is required.');

    $claimed = with_room($code, function ($room) {
        $hasAi = false;
        foreach ($room['participants'] as $p) { if ($p['type'] === 'ai') { $hasAi = true; break; } }
        if (!$hasAi) fail(400, 'No AI participants in this room.');
        return [$room, claim_ai_round($room)];
    });

    if ($claimed) {
        run_ai_round($code);
    }

    $state = with_room($code, fn($room) => [null, public_state($room)]);
    echo json_encode(['state' => $state, 'claimed' => $claimed]);
    break;
}

default:
    fail(400, 'Unknown action.');
}
