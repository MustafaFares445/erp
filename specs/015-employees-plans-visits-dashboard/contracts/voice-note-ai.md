# Contract: Voice-Note Transcription and AI Isolation

**Source of truth**: the `VoiceNoteTranscriber` interface and `TranscribeVoiceNoteJob`. Settles D6
and implements Constitution Principle V (AI Isolation & Human Oversight).

## Flow

```text
Visit completed ──> VoiceNoteIntakeService
                      ├─ store audio in the private media collection
                      ├─ reject oversized audio up front (max-bytes guard)
                      ├─ create voice_note_transcriptions row (Pending)
                      └─ dispatch TranscribeVoiceNoteJob  ── queue ──┐
                                                                      │
Visit status is already final; nothing below can change it. <─────────┘
                      │
                      ├─ success: transcript, confidence + confidence_source,
                      │           detected_language, status Succeeded
                      │             └─ KeywordDetectionService → drafts (Draft)
                      └─ failure: error_message, status Failed, retries bounded
```

A test must assert that a throwing transcriber leaves visit status, performance scores, and salary
untouched. Drafts and bonus suggestions never reach `Approved` without a recorded human decision
(FR-054, FR-064).

## Provider boundary

```php
interface VoiceNoteTranscriber
{
    public function transcribe(TranscriptionRequest $request): TranscriptionResult;
}
```

- `OpenAiWhisperTranscriber` — the production driver (D6).
- `FakeVoiceNoteTranscriber` — deterministic, used in every test and available locally; the test
  environment forces it so no test reaches the network.
- `TranscriptionRequest`/`TranscriptionResult` are Spatie Data DTOs. `TranscriptionResult` carries
  text, confidence, `confidence_source`, `detected_language`, provider identity, and any provider
  error.
- Bound in a service provider from config, so business logic depends only on the interface.
  **ArchTest must assert that no class outside the driver namespace references the OpenAI client**,
  mirroring the existing stock-write ban.

## Configuration

```text
OPENAI_API_KEY
OPENAI_TRANSCRIBE_MODEL=whisper-1
OPENAI_TRANSCRIBE_BASE_URL
OPENAI_TRANSCRIBE_TIMEOUT=120
EMPLOYEES_TRANSCRIBE_DRIVER=openai          # openai | fake  (tests force fake)
EMPLOYEES_TRANSCRIBE_MAX_BYTES=26214400     # 25 MiB provider request limit
EMPLOYEES_DEFAULT_REQUIRED_VISIT_MINUTES=30 # D5 fallback threshold
```

Document all of these in `Docs/CONFIGURATION.md` and `.env.example`.

## Retry policy (see `research.md` R-003 for rationale)

- `$tries = 3` with exponential backoff `[60, 300]` seconds.
- Retry only transport failures, timeouts, HTTP 429, and 5xx.
- **Never retry** a 4xx caused by the payload itself (unsupported format, file too large, empty
  recording) — those go straight to `Failed` with the provider message.
- `failed()` writes `TranscriptionStatus::Failed`, `VoiceNoteStatus::Failed`, and `error_message`,
  and touches nothing else.

## Language coverage (FR-055)

Whisper auto-detects language, which is what mixed Arabic/English recordings need:

- Pass the `language` parameter **only** when `employee_voice_notes.language` is explicitly set;
  leave it unset otherwise so detection can run.
- Store the detected language in `voice_note_transcriptions.detected_language`.
- Arabic dialects transcribe into Arabic script with dialect vocabulary preserved; accuracy is lower
  than for Modern Standard Arabic, and the same is true for strongly accented English. This is a
  documented, known limitation, not a rejected input.
- *Optional, off by default*: the driver may pass the active `AiKeywordRule` keywords as the Whisper
  `prompt` to bias recognition of product names — a documented enhancement behind a config flag, not
  a requirement.

## Confidence fallback (FR-056; see `research.md` R-002 for the derivation formula)

The OpenAI audio transcription API does not return a calibrated confidence score. The driver defines
an explicit, labeled behavior instead of inventing a number:

| Situation | `confidence` | `confidence_source` | UI |
|---|---|---|---|
| Provider returns a genuine confidence field | the value, 0.00–100.00 | `ProviderReported` | `87.50%` |
| `verbose_json` segments with `avg_logprob` available | duration-weighted mean of `exp(avg_logprob)` × 100, clamped to 0.00–100.00 | `DerivedFromLogProb` | `≈ 87.50%` + tooltip naming the derivation |
| No segments, no log-probabilities, or a non-verbose response format | `NULL` | `Unavailable` | "Not reported by provider" |

Rules:

- `0.00 <= confidence <= 100.00` whenever non-null; both boundaries tested.
- `confidence` is `NULL` **if and only if** `confidence_source = Unavailable`. A missing confidence
  is never stored as `0.00` — `0.00` means the model had zero confidence, a materially different
  claim.
- A derived value is never labeled `ProviderReported`.
- Confidence, of any source, **must not** be used to auto-reject or auto-approve anything — derived
  values run lower for dialect and accented audio, and Principle V requires every AI output to reach
  a human decision regardless of how confident the model appears (FR-057).
- A response that returns HTTP 200 but fails to parse into the expected schema (missing expected
  fields, unexpected types) is treated as absent confidence data — `Unavailable` — not as a
  retryable transport error. Only network-level failures, timeouts, HTTP 429, and 5xx (Retry policy,
  above) trigger a job retry; a malformed 200 response completes the job successfully with
  `Unavailable` confidence instead of being retried.

## Guarantees

- A transcription failure never changes visit status, a performance score, or a salary calculation.
- No class outside the transcriber driver namespace references the OpenAI client.
- No test in this feature performs network I/O.
- No AI output (transcript, keyword match, opportunity draft, bonus suggestion) takes operational
  effect without a recorded human decision.

## Verification

- A throwing transcriber leaves visit/score/salary untouched; `error_message` is persisted; retries
  are bounded; a 4xx payload error is not retried; oversized audio is rejected before dispatch.
- Confidence boundary values `0.00` and `100.00` are accepted; out-of-range values are refused.
- `confidence` is null if and only if `confidence_source = Unavailable`; a derived value is never
  labeled `ProviderReported`; the UI renders "Not reported by provider" and never `0.00%` for a null.
- `language` is omitted from the request when the voice note has none, passed when set;
  `detected_language` is persisted from the response.
- No draft or bonus suggestion reaches `Approved` without a recorded decision; `Approved`/`Rejected`
  are terminal.
- `ArchTest` extension: no class outside the driver namespace references the OpenAI client.
