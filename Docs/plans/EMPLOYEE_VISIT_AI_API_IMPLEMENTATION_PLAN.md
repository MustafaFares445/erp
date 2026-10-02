---
status: active-plan
owner: employees
last_verified: 2026-10-02
verified_against: current Employees backend, approved Employee App V1 behavior, current route table
---
# Employee Visit AI API Implementation Plan

**Status:** Deferred / implement with the future Employee API workstream  
**Date:** 2026-10-02  
**Scope:** Employee mobile visit APIs, AI voice-note processing, product-opportunity detection, and employee confirmation  
**Implementation target:** Laravel API monolith

## 1. Purpose

This document captures the approved implementation direction for the employee visit voice-note flow so it can be implemented later together with the employee-facing API endpoints.

No employee-facing API is implemented by this document. ADR 0003 currently excludes `/api/employee` and requires a separate specification plus either a new ADR or an explicit ADR 0003 amendment before implementation.

The target user flow is:

```text
Visit list -> Visit details -> Check in -> Active visit
-> Record voice note -> AI processing -> Review transcript/outcome
-> Review product opportunities -> Confirm selected products
-> Complete visit -> Completed summary -> Visit conversation
```

## 2. Confirmed Product Decisions

- Target market is UAE.
- Employees may speak Arabic, English, Hindi, Urdu, or mixed/code-switched speech.
- The API must not require or persist an employee preferred transcription language.
- Voice transcription always uses provider auto-detection.
- The current free/pilot provider is Groq.
- Speech-to-text model: `whisper-large-v3`.
- Opportunity extraction model: `qwen/qwen3.8-27b`.
- Both models use the same Groq API key.
- The original mixed-language transcript must be retained.
- Qwen extracts structured business intent only; it must never invent product IDs, variant IDs, prices, stock, or SQL.
- Laravel converts the structured search intent into parameterized Eloquent/Query Builder queries.
- Product suggestions shown to the employee must come from real ERP database records.
- The employee confirms the final visit outcome and selected products.
- Employee confirmation does not equal admin approval of an AI-originated sales opportunity.
- AI failure must never block visit check-out or visit completion.
- The provider boundary must remain replaceable.

## 3. Existing Code Baseline

The Employees dashboard workstream already provides useful backend foundations:

- `VoiceNoteTranscriber` is the transcription provider boundary.
- `OpenAiWhisperTranscriber` is the current production implementation.
- `FakeVoiceNoteTranscriber` is used by tests/local isolation.
- `VoiceNoteIntakeService` stores voice-note media and creates a pending transcription.
- `TranscribeVoiceNoteJob` performs queued transcription with retries.
- `VoiceNoteTranscription` stores transcript, confidence, detected language, provider, error, and status.
- `KeywordDetectionService` currently creates draft opportunities from configured keywords.
- `SalesOpportunityStatus::Draft` is the AI human-review state.
- `OpportunityReviewService` preserves the existing admin approve/reject gate.
- Spatie Media Library is the canonical voice-note storage mechanism.
- Spatie Activitylog is the canonical audit trail.
- Current tests enforce provider isolation and 100% project coverage requirements.

The future API should extend these components rather than create a parallel voice-note or opportunity subsystem.

## 4. Required Governance Before Coding

Before any `/api/employee` implementation:

1. Create a dedicated employee-mobile/API specification.
2. Add a new ADR or explicitly amend ADR 0003.
3. Define employee mobile authentication/token behavior.
4. Confirm visit ownership and assignment authorization rules.
5. Confirm location permission, GPS retention, and privacy rules.
6. Confirm whether completed-visit conversation is part of the same feature or a separate messaging feature.

The API contract can then be updated from aspirational to implemented status.

## 5. Configuration Plan

Use configuration, never hardcoded provider credentials or model names.

Recommended environment variables:

```env
GROQ_API_KEY=
GROQ_BASE_URL=https://api.groq.com/openai/v1
EMPLOYEES_TRANSCRIBE_DRIVER=groq
GROQ_TRANSCRIBE_MODEL=whisper-large-v3
GROQ_TRANSCRIBE_TIMEOUT=120
EMPLOYEES_OPPORTUNITY_DRIVER=groq
GROQ_OPPORTUNITY_MODEL=qwen/qwen3.8-27b
GROQ_OPPORTUNITY_TIMEOUT=60
EMPLOYEES_TRANSCRIBE_MAX_BYTES=26214400
FEATURE_AI_VOICE_NOTES=true
```

Do not add a transcription-language environment value.

Do not add a separate Qwen API key. Qwen is called through Groq and uses `GROQ_API_KEY`.

Add Groq settings under `config/services.php` and provider selection under `config/employees.php`.

Tests must continue forcing fake providers and must never call Groq over the network.

## 6. Provider Architecture

### 6.1 Transcription

Add:

```text
GroqWhisperTranscriber implements VoiceNoteTranscriber
```

Keep `OpenAiWhisperTranscriber` temporarily as a supported fallback until a later cleanup decision.

The Groq adapter should use Laravel `Http` directly, matching the existing provider-isolation approach. Do not introduce an SDK only for this feature unless there is a separate architecture decision.

Request requirements:

- endpoint: `/audio/transcriptions`
- model: `whisper-large-v3`
- omit the `language` field completely
- use a response format that returns transcript plus detected language when available
- use timeout from config
- classify 429/5xx/connection failures as retryable
- classify invalid payload/unsupported media 4xx as non-retryable
- never log the Groq API key

Store the detected language only as transcription metadata. It is not an employee preference.

### 6.2 Opportunity Extraction

Introduce a second provider boundary:

```php
interface VisitOpportunityExtractor
{
    public function extract(OpportunityExtractionRequest $request): OpportunityExtractionResult;
}
```

Implement:

```text
GroqQwenOpportunityExtractor
FakeVisitOpportunityExtractor
```

The production driver calls `qwen/qwen3.8-27b` using strict structured JSON output.

Qwen must receive the transcript and limited visit context only. It must not receive database credentials and must not be allowed to execute SQL.

## 7. Structured Extraction Contract

The extraction result should be strongly typed and equivalent to:

```json
{
  "opportunity_detected": true,
  "visit_outcome": "Customer requested a quotation for replacement pumps.",
  "products": [
    {
      "original_phrase": "three infusion pumps",
      "search_text": "infusion pump",
      "quantity": 3,
      "unit": "pcs",
      "intent": "quotation",
      "timeframe": "next month",
      "requirements": ["220V", "EU plug"]
    }
  ]
}
```

Validation rules:

- `opportunity_detected` is required boolean.
- `visit_outcome` is concise plain text.
- `products` may be empty.
- Quantity is nullable if not stated; never fabricate one.
- Search text is descriptive text, never SQL.
- Product IDs, variant IDs, SKUs, prices, stock values, and currencies are forbidden in AI-generated fields unless copied verbatim from explicit context supplied by Laravel.
- Unknown requirements remain unknown rather than guessed.

## 8. Job Pipeline

Refactor the current single pipeline into explicit asynchronous stages:

```text
VoiceNoteIntakeService
  -> TranscribeVoiceNoteJob
      -> VoiceNoteTranscriber
      -> save VoiceNoteTranscription
      -> ExtractVisitOpportunitiesJob
          -> VisitOpportunityExtractor
          -> save structured extraction
          -> ProductOpportunityResolver
          -> save/query real candidate products
          -> mark AI review ready
```

Keep transcription and opportunity extraction as separate jobs so each stage can retry and fail independently.

A transcription failure stops only downstream AI processing for that note. It never changes the visit to failed and never blocks check-out.

An opportunity-extraction failure leaves the successful transcript available for manual review and manual product selection.

## 9. Language Handling

The employee API must not accept a language parameter for voice notes.

When the API creates an `EmployeeVoiceNote`:

- do not set an employee language preference
- do not infer a preferred language from profile/country
- let Whisper auto-detect per recording

The existing nullable `employee_voice_notes.language` column may remain temporarily for backward compatibility, but the future mobile API must always write it as `null`.

`voice_note_transcriptions.detected_language` remains valid provider/result metadata.

After the API workstream is stable, a separate cleanup migration may remove the legacy voice-note language column if no dashboard or historical requirement still depends on it.

## 10. Product Resolution Rules

AI output must never be executed as SQL.

Introduce a domain service such as:

```text
ProductOpportunityResolver
```

Responsibilities:
- accept validated `search_text`, quantity, unit, and requirements
- normalize whitespace/case without destroying product codes
- search active products and active variants only
- prefer exact SKU/code matches when the transcript contains a code
- search product name, variant name, SKU, brand, and relevant searchable attributes
- return a bounded candidate set
- preserve requested quantity separately from product matching
- never mutate inventory, pricing, or products
- never create a quotation automatically

Use Eloquent/Query Builder bindings only.

For V1, a safe deterministic search is acceptable: exact code/SKU first, then token/name matches. A future full-text/vector search can replace the resolver internals without changing the AI contract.

## 11. Suggested Persistence

Do not overload the transcript row with all opportunity-review state.

Recommended normalized additions for the API workstream:

### `voice_note_ai_extractions`

- `id`
- `voice_note_transcription_id` unique FK
- `provider`
- `model`
- `opportunity_detected`
- `suggested_visit_outcome`
- `status` (pending/processing/ready/failed)
- `structured_payload` JSON for validated provider output
- `error_message` nullable
- `processed_at`

### `voice_note_product_suggestions`
- `id`
- `voice_note_ai_extraction_id` FK
- `original_phrase`
- `search_text`
- `requested_quantity` nullable decimal
- `requested_unit` nullable
- `intent` nullable
- `timeframe` nullable
- `requirements` JSON nullable

### `voice_note_product_candidates`

- `id`
- `voice_note_product_suggestion_id` FK
- `product_id` FK
- `product_variant_id` nullable FK
- `rank`
- `match_reason` nullable
- `selected_by_employee` boolean
- `selected_at` nullable timestamp

If selected products must become canonical opportunity lines, add a dedicated `sales_opportunity_products` relation instead of keeping confirmed commercial data only in AI-candidate tables.

All schema names remain subject to the future API spec review before migration creation.

## 12. Relationship With Existing Keyword Detection

The current `KeywordDetectionService` is a simple keyword-rule implementation and should not be deleted during the first API change.

For the mobile API path:

- Qwen structured extraction becomes the primary opportunity detector.
- Product resolution comes from the ERP database.
- The old keyword detector can remain for legacy/dashboard-originated workflows until migration is complete.
- Do not run both detectors in a way that creates duplicate draft opportunities.

After the new API flow is proven, decide whether `AiKeywordRule` remains useful as a deterministic fallback, product-hint source, or is deprecated.

## 13. Employee API Endpoint Plan

The following routes should be implemented only after governance approval.

### Visit lifecycle

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/employee/visits` | Assigned visit list |
| GET | `/api/employee/visits/{visit}` | Visit details and required actions |
| POST | `/api/employee/visits/{visit}/check-in` | Confirm check-in and initial GPS |
| POST | `/api/employee/visits/{visit}/gps` | Append/batch GPS trail points |
| POST | `/api/employee/visits/{visit}/check-out` | Complete visit and stop tracking |
| GET | `/api/employee/visits/{visit}/summary` | Completed visit summary |

Authorization: employee may access only visits assigned to that employee.

### Voice notes and AI

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/employee/visits/{visit}/voice-notes` | Upload audio and queue AI processing |
| GET | `/api/employee/visits/{visit}/voice-notes` | List notes with processing state |
| GET | `/api/employee/voice-notes/{voiceNote}` | Note/transcription status and result |
| POST | `/api/employee/voice-notes/{voiceNote}/retry` | Retry failed AI processing when allowed |
### Visit review and products

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/employee/visits/{visit}/review` | Transcript, suggested outcome, opportunities, DB candidates |
| PATCH | `/api/employee/visits/{visit}/review` | Confirm/edit outcome and selected products |
| GET | `/api/employee/products/search` | Manual authenticated product search for Add Product |

The review request should submit candidate/product IDs returned by Laravel, never AI-generated identifiers.

### Completed visit conversation

If included in the approved API spec:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/employee/visits/{visit}/conversation` | Chronological admin/employee messages |
| POST | `/api/employee/visits/{visit}/conversation` | Employee reply |

Conversation implementation should reuse an existing messaging/interaction model if appropriate rather than introducing an unnecessary parallel message system.

## 14. API Response State Model

Voice-note processing states exposed to mobile:

```text
queued
processing_transcription
processing_opportunities
ready
failed_transcription
failed_opportunity_extraction
```

A successful upload returns `202 Accepted` immediately.

The mobile app must never wait synchronously for Whisper/Qwen to finish.

The review endpoint aggregates persisted state into the screen-ready payload.
Example shape:

```json
{
  "visit_id": 91,
  "voice_notes": [
    {
      "id": 482,
      "status": "ready",
      "transcript": "Customer needs two infusion pumps...",
      "detected_language": "en"
    }
  ],
  "suggested_outcome": "Customer requested a Q4 replacement quotation.",
  "opportunities": [
    {
      "quantity": 2,
      "unit": "pcs",
      "search_text": "infusion pump",
      "candidates": []
    }
  ],
  "employee_confirmed": false
}
```

## 15. Visit Completion Rules

Check-out remains a domain action, not an AI action.

Before check-out, the API may require:

- visit is currently checked in
- required review acknowledgment is complete when AI results are ready
- employee explicitly chooses selected products or `No Opportunity`
- employee confirms final visit outcome
- pending GPS points are submitted/synchronized when connectivity permits

AI processing still running must not automatically block completion unless the future business spec explicitly changes this rule.
If AI completes after check-out, the result may remain reviewable by admin, but it must not silently change the employee-confirmed visit outcome.

On check-out:

- set server-side `checked_out_at`
- stop accepting normal active-visit GPS points
- derive duration from check-in/check-out timestamps
- retain original audio/transcript/audit evidence
- return completed summary

## 16. Security and Privacy

- All employee endpoints require authenticated employee tokens.
- Enforce visit ownership/assignment in policies or service preconditions.
- Store voice audio privately through Spatie Media Library.
- Never expose local storage paths.
- Use authorized/signed temporary access where playback is allowed.
- Validate MIME type, extension, content size, and duration limits.
- Rate-limit upload/retry endpoints.
- Never log audio content, Groq secret, authorization tokens, or full provider payloads containing sensitive data.
- Sanitize provider errors before returning them to mobile.
- Qwen never receives DB credentials or raw SQL execution capability.
- SQL/Product queries use bindings only.
- Audit check-in, check-out, review confirmation, selected products, retries, and admin review decisions.

## 17. Free-Pilot Provider Behavior

The first API release may use Groq Free Plan limits for pilot/testing.

Implementation must not assume a specific free quota in business logic because provider limits may change.

Handle provider HTTP 429 as a retryable/transient state with bounded backoff.
If the free quota is exhausted:

- preserve uploaded audio
- show processing delayed/failed state
- allow configured retry
- allow manual visit outcome/product review
- allow visit completion

Provider/model strings stored on transcription/extraction rows provide traceability if the production provider changes later.

## 18. Testing Plan

### Unit tests

- Groq transcription adapter request shape omits language.
- Groq transcription adapter parses transcript/detected language.
- retryable vs non-retryable provider failures.
- Qwen extractor validates strict structured response.
- invalid quantities/unknown fields are rejected.
- product resolver exact SKU precedence.
- product resolver name/variant matching.
- product resolver never executes model-provided SQL.

### Feature tests

- employee can list only assigned visits.
- other employee visit access is forbidden.
- check-in records server timestamp and valid GPS.
- voice upload returns 202 and queues work.
- API rejects unsupported/oversized audio.
- API rejects/ignores any submitted transcription language field.
- processing states map correctly.
- employee can confirm DB-returned products.
- arbitrary/nonexistent product IDs are rejected.
- `No Opportunity` is supported.
- AI failure does not block check-out.
- completed visit cannot accept normal active GPS updates.

### Queue/integration tests
- transcription -> extraction -> product resolution chain.
- duplicate job delivery is idempotent.
- retry behavior for 429/5xx.
- extraction failure preserves successful transcript.
- no duplicate sales opportunity from repeated job execution.
- fake providers are mandatory in automated tests.

### Architecture tests

- Groq HTTP/client references are confined to provider adapters.
- controllers remain thin.
- product search business logic stays in services.
- tests never access the network.

The project-wide `composer test`, PHPStan, type coverage, and code coverage requirements must remain green at the repository's configured 100% thresholds.

## 19. Implementation Phases

### Phase 0 - Governance
- approve employee mobile API spec
- add/amend ADR
- finalize auth and privacy requirements

### Phase 1 - Provider/config foundation
- add Groq service config
- add `GroqWhisperTranscriber`
- bind driver through existing `VoiceNoteTranscriber`
- add Qwen extraction interface/fake/driver

### Phase 2 - AI persistence and DTOs
- migrations/models for extraction/suggestions/candidates
- typed request/result DTOs
- audit coverage

### Phase 3 - Async pipeline
- separate transcription and opportunity jobs
- idempotency/retries
- processing statuses
- failure isolation

### Phase 4 - Product resolver
- safe database search
- ranking
- candidate persistence
- quantity/requirements retention

### Phase 5 - Employee visit API
- visit list/details
- check-in/GPS
- voice-note upload/status
- visit review/product selection
- check-out/summary

### Phase 6 - Conversation API
- only if included in approved scope
- chronological review conversation
- authorization/audit

### Phase 7 - QA and rollout
- UAE mixed-language audio test corpus
- product/SKU/quantity accuracy benchmark
- API/mobile end-to-end flow
- queue failure/quota tests
- production config and monitoring

## 20. Acceptance Criteria

The future implementation is complete only when:

- employees never choose a transcription language
- voice recordings are automatically transcribed from mixed UAE workforce speech
- transcript remains visible even when opportunity extraction fails
- AI returns structured product intent and quantity without database IDs
- Laravel resolves product candidates from real product/variant records
- the employee can add/remove/confirm products before visit completion
- confirmed products retain requested quantities
- no AI output is executed as raw SQL
- no product, price, SKU, stock, or quantity is fabricated by the backend from AI guesses
- AI-originated opportunities remain subject to the existing human/admin review gate
- retryable provider failures retry safely
- AI failure/quota exhaustion does not block visit completion
- all private audio remains access-controlled
- all relevant actions are audited
- API ownership rules prevent cross-employee access
- automated tests stay fully offline and all project quality gates pass

## 21. Explicit Non-Goals

This plan does not implement:

- the endpoints now
- mobile authentication
- the employee mobile UI
- real-time streaming transcription while recording
- automatic quotation creation
- automatic inventory reservation
- automatic opportunity approval
- direct AI-to-database SQL execution
- employee-language preferences
- a second audit/media/permission subsystem
- a permanent commitment to Groq

## 22. Future Production Upgrade Path

Keep both provider boundaries stable so the free pilot can later move independently:

```text
VoiceNoteTranscriber
  Groq Whisper -> OpenAI / Speechmatics / another STT provider

VisitOpportunityExtractor
  Groq Qwen -> another structured-output LLM
```

The employee API contract, visit workflow, product resolver, database records, and review screens should not need redesign when providers change.
