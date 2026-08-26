# Group Rooms + Shorter Replies — Design

Date: 2026-08-26
Resolves GitHub issues #1 (group calls with multiple AIs + human participants),
#2 (AI replies too long), and #3 (chat groups where AI characters debate).

## Problem

`chat.html` is a single-page AI personality chat (1 human ↔ 1 AI). `api.php` is a
stateless PHP proxy to OpenAI. Three requests:

- **#2** — AI replies, especially openers, are too long. Make them short & conversational.
- **#3** — Let a user create a group where multiple AI characters debate a question.
- **#1** — Let multiple *human* participants and multiple AIs share one conversation.

#1 and #3 are the same core capability (a multi-participant conversation) at different
participant counts, so they are built once as **Group Rooms**.

## Approach

### Issue #2 — Shorter replies
- Add a length rule to every AI system prompt: default 2–4 sentences, conversational;
  greetings 1–2 lines; only elaborate when the user explicitly asks.
- Reduce `max_tokens` 800 → 300 in the OpenAI call.

### Issues #1 + #3 — Group Rooms (server-backed, client-polled)
Real multi-human sync without websockets: a server-side room store that clients poll.
Fits the existing PHP host — no new runtime.

**Backend**
- `openai.php` — shared helper (`wyssey_openai_chat($messages, $opts)`) refactored out of
  `api.php` so both the 1-on-1 proxy and rooms reuse one OpenAI call path. Returns the
  assistant text or throws.
- `api.php` — unchanged behavior, now delegates the OpenAI call to `openai.php`; shorter
  `max_tokens`.
- `room.php` — file-based rooms in `rooms/<CODE>.json`, guarded by `flock`. Actions:
  - `create` `{roomName, displayName, ais:[{id,name,personality,image,category}]}` →
    creates room, adds creator (human) + AI participants, returns `{code, participantId, state}`.
  - `join` `{code, displayName}` → adds a human participant, returns `{participantId, state}`.
  - `state` `{code, since}` → messages with `seq > since` + participants + `aiRunning` flag.
  - `post` `{code, participantId, content}` → append human message, then run **one
    round-robin debate round**: each AI participant, in order, is called with the recent
    transcript + its persona + a "group debate, keep it short, react to the others"
    instruction; each reply is appended as it is produced so pollers see them stream in.
  - `continue` `{code}` → run one more AI round with no new human message.

**Concurrency**
- Every read/modify/write of a room file holds an exclusive `flock`.
- An AI round is guarded by an `aiRunning` timestamp in the room JSON. A client claiming a
  round sets it; a stale claim (> 60s) can be reclaimed. This makes AI rounds exactly-once
  even when several humans poll/post at the same time.
- Messages carry a monotonic `seq`; polling is `since=<lastSeq>`.

**Frontend (`chat.html`)**
- "Create Group" action in the home header → modal (group name, your display name,
  checkbox multi-select of predefined + custom characters).
- Create → show a shareable link `?room=CODE` (copy button) and enter the room.
- On load, if `location.search` has `room=CODE`, prompt for a display name and `join`.
- Room view reuses chat styling. Each message shows sender name + avatar (AI image /
  colored initials; humans get a colored initial). Participant chips show who is present.
- Poll `state` every 2s while a room is open; render appended messages; show a typing
  indicator while `aiRunning`. A "Continue debate 🔄" button calls `continue`.
- 1-on-1 chat path is unchanged except for the shorter-reply prompt.

## Data shapes

Room file `rooms/<CODE>.json`:
```json
{
  "code": "AB12CD",
  "name": "Investing debate",
  "createdAt": 1750000000,
  "aiRunning": 0,
  "seq": 4,
  "participants": [
    {"id":"p_x","name":"Olivier","type":"human"},
    {"id":"ai_warren-buffett","name":"Warren Buffett","type":"ai",
     "characterId":"warren-buffett","image":"https://…","personality":"…"}
  ],
  "messages": [
    {"seq":1,"senderId":"p_x","senderName":"Olivier","senderType":"human",
     "content":"Is gold a good hedge?","ts":1750000001}
  ]
}
```

## Error handling
- Room store not writable / room missing / bad action → JSON `{error}` + proper HTTP code;
  client surfaces it and keeps polling (transient errors recover on the next tick).
- OpenAI failure inside a round → that AI's turn is skipped with a system note; the round
  continues and `aiRunning` is always cleared in a `finally`.
- `flock` serializes writers; readers get a consistent snapshot.

## Testing
- `php -l` on every PHP file.
- CLI harness drives `room.php` create → post → state with OpenAI stubbed via an env flag
  (`WYSSEY_FAKE_AI=1`) so round-robin, locking, and `seq` are verified without network.

## Out of scope (flagged, not changed)
- The OpenAI API key is hardcoded in `chat.html` and `api.php` and committed to the repo —
  a real credential leak. Called out to the user separately; not fixed under these issues.
