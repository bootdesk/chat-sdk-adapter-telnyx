# adapter-telnyx

Telnyx adapter for bootdesk/chat-sdk-core (SMS, MMS, RCS). Namespace: `BootDesk\ChatSDK\Telnyx`

## files
- `TelnyxAdapter` — implements `Adapter` using Telnyx Messaging API
- `TelnyxFormatConverter` — Telnyx text ↔ CommonMark AST
- `TelnyxWebhookVerifier` — Ed25519 signature verification (`ext-sodium`)

## registration
`src/register.php` registers `'telnyx' => TelnyxAdapter::class` via `AdapterRegistry`

## constructor
```php
new TelnyxAdapter(
    string $apiKey,
    string $messagingProfileId,
    string $publicKey,
    string $fromNumber,
    ?string $agentId = null,
    ClientInterface $httpClient,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`telnyx:{fromNumber}:{toNumber}` for SMS/MMS, `telnyx:{agentId}:{phoneNumber}` for RCS — per-conversation thread

## contracts implemented
- `HandlesStatuses` — `parseStatus()` for `message.sent`, `message.finalized`, `message.read` outbound events (SMS/MMS + RCS)
- `HandlesSlashCommands` — `parseSlashCommand()` for any inbound message starting with `/`

## webhook flow
1. `verifyWebhook` — verifies Ed25519 signature from `Telnyx-Signature-Ed25519` header using `sodium_crypto_sign_verify_detached`
2. `parseStatus` (HandlesStatuses) — handles `message.finalized` → `delivered`/`delivery_failed`/`sending_failed`/`delivery_unconfirmed`, and `message.read` → `read`
3. `parseSlashCommand` (HandlesSlashCommands) — intercepts `message.received` where text starts with `/`, extracts command + args
4. `parseWebhook` — handles remaining `message.received` events, extracts text and media

## features
- Send SMS/MMS — max 1600 chars, auto-split into segments
- RCS support via `agent_id` (richer messaging)
- RCS text truncation: `text` capped at 3072 chars, card `description` at 2000 chars
- Media attachments (MMS with image URL or media upload)
- Fetch messages from Telnyx API
- No editing/deletion (SMS protocol limitation)
- No reactions, no typing indicators
- Streaming: concatenates chunks into single message
- Delivery status tracking (delivered, read, failed)
- Slash command support (any message starting with `/`)
- Thread ID: uses `from.agent_id` for RCS, `from.phone_number` for SMS/MMS

## config (laravel)
```php
'telnyx' => [
    'api_key' => env('TELNYX_API_KEY'),
    'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
    'public_key' => env('TELNYX_PUBLIC_KEY'),
    'from_number' => env('TELNYX_FROM_NUMBER'),
    'agent_id' => env('TELNYX_AGENT_ID'),
],
```
