# Configuration Documentation

## 1. Environment Variables

| Key | Required | Example | Description |
|---|---|---|---|
| APP_NAME | Yes | IERP | Application name |
| APP_ENV | Yes | production | Runtime environment |
| APP_KEY | Yes | base64:... | Laravel encryption key |
| APP_URL | Yes | https://api.example.com | API URL |
| DB_CONNECTION | Yes | mysql | Database driver |
| DB_HOST | Yes | 127.0.0.1 | Database host |
| DB_PORT | Yes | 3306 | Database port |
| DB_DATABASE | Yes | ierp | Database name |
| DB_USERNAME | Yes | ierp_user | Database user |
| DB_PASSWORD | Yes | secret | Database password |
| QUEUE_CONNECTION | Yes | database | Queue driver |
| CACHE_STORE | Yes | database | Cache store |
| FILESYSTEM_DISK | Yes | private | Storage disk |
| MAIL_MAILER | Yes | smtp | Mail driver |
| MAIL_HOST | Yes | smtp.example.com | Mail host |
| MAIL_USERNAME | Yes | user | Mail username |
| MAIL_PASSWORD | Yes | secret | Mail password |
| STRIPE_SECRET | Yes | sk_live_xxx | Stripe secret key |
| STRIPE_WEBHOOK_SECRET | Yes | whsec_xxx | Stripe webhook signing secret |
| AI_TRANSCRIPTION_PROVIDER | Optional | openai/other | AI provider key name |
| AI_TRANSCRIPTION_API_KEY | Optional | secret | API key for transcription provider |
| FEATURE_AI_VOICE_NOTES | Yes | true | Enable AI module |
| FEATURE_WEBSITE_SYNC | Yes | false | Must be false for current scope |
| OPENAI_API_KEY | Yes (Employees module) | sk-... | OpenAI credential for voice-note transcription (D6) |
| OPENAI_TRANSCRIBE_MODEL | Yes (Employees module) | whisper-1 | Whisper model used by `OpenAiWhisperTranscriber` |
| OPENAI_TRANSCRIBE_BASE_URL | Optional | https://api.openai.com/v1 | Override for a proxy/compatible endpoint |
| OPENAI_TRANSCRIBE_TIMEOUT | Yes (Employees module) | 120 | Request timeout in seconds |
| EMPLOYEES_TRANSCRIBE_DRIVER | Yes (Employees module) | openai/fake | `VoiceNoteTranscriber` driver; tests force `fake` |
| EMPLOYEES_TRANSCRIBE_MAX_BYTES | Yes (Employees module) | 26214400 | Max voice-note audio size (25 MiB), rejected before dispatch |
| EMPLOYEES_DEFAULT_REQUIRED_VISIT_MINUTES | Yes (Employees module) | 30 | Work-time adherence fallback threshold (D5) |

## 2. App Configuration

Use config files for payment methods, tax defaults, file limits, queue names, and notification channels. Avoid hardcoding business settings.

## 3. Database Configuration

Use a relational database. MySQL or PostgreSQL are acceptable pending final selection.

## 4. Storage Configuration

Private disk is required for invoices, proofs, attachments, and voice notes. Use signed routes for downloads.

## 5. Cache Configuration

Cache stable lookup data only. Do not cache stock balances or accounting balances unless invalidation is implemented.

## 6. Queue Configuration

Queue jobs for PDF, email, Stripe, tax, journal posting, AI, notifications, and exports.

## 7. Mail Configuration

Mail is required for invoice sending and reminders.

## 8. Stripe Configuration

Store Stripe secrets in environment variables. Verify webhook signatures.

## 9. AI Integration Configuration

AI provider must be replaceable. Feature flag must allow disabling AI processing while preserving visit flow.

## 10. Notification Configuration

Support database notifications by default; email and push depend on configured providers.

## 11. Feature Flags

| Feature | Default | Notes |
|---|---|---|
| FEATURE_AI_VOICE_NOTES | true | Required module but should fail safely. |
| FEATURE_WEBSITE_SYNC | false | Website skipped now. |
| FEATURE_SUPPLIER_PORTAL | false | Supplier portal out of scope. |
| FEATURE_MANUAL_PAYMENTS | true | Required. |

## 12. Secrets Management

Secrets must not be committed. Use environment variables or deployment secret store.

## 13. Open Questions

- Confirm file size limits.
- Confirm AI provider.
- Confirm database engine.
