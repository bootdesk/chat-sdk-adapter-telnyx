# bootdesk/chat-sdk-adapter-telnyx

Telnyx adapter for the BootDesk multi-platform messaging SDK. Supports SMS, MMS, and RCS.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-telnyx
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable | Description | Example |
|----------|-------------|---------|
| `api_key` | Telnyx API V2 Key | `KEY...` |
| `http_client` | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `messaging_profile_id` | Messaging Profile UUID | `16fd2706-...` |
| `public_key` | Ed25519 public key for webhook verification | `base64...` |
| `from_number` | Default sender phone number (E.164) | `+15551234567` |
| `agent_id` | RCS agent ID (enables RCS sending) | `e4448a5c...` |

```php
use BootDesk\ChatSDK\Telnyx\TelnyxAdapter;

$adapter = new TelnyxAdapter(
    apiKey: env('TELNYX_API_KEY'),
    httpClient: new \GuzzleHttp\Client,
    messagingProfileId: env('TELNYX_MESSAGING_PROFILE_ID'),
    publicKey: env('TELNYX_PUBLIC_KEY'),
    fromNumber: env('TELNYX_FROM_NUMBER'),
    agentId: env('TELNYX_AGENT_ID'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'telnyx' => [
    'api_key' => env('TELNYX_API_KEY'),
    'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
    'public_key' => env('TELNYX_PUBLIC_KEY'),
    'from_number' => env('TELNYX_FROM_NUMBER'),
    'agent_id' => env('TELNYX_AGENT_ID'),
],
```

Override the HTTP client by setting `http_client` in config or rebinding `ClientInterface` in the container.

## Sending Messages

The adapter auto-selects the endpoint based on whether `agent_id` is set:

- **No `agent_id`** → `POST /v2/messages` (SMS/MMS via long code, short code, or number pool)
- **With `agent_id`** → `POST /v2/messages/rcs` (RCS with SMS/MMS fallback)

### SMS

```php
$adapter->postMessage(
    'telnyx:+15551234567:+15559876543',
    PostableMessage::text('Hello from BootDesk!')
);
```

### MMS

```php
$adapter->postMessage(
    'telnyx:+15551234567:+15559876543',
    new PostableMessage(
        content: 'Check this out',
        attachments: [['url' => 'https://example.com/photo.jpg']],
    )
);
```

### RCS (text)

```php
$adapter = new TelnyxAdapter(
    apiKey: env('TELNYX_API_KEY'),
    httpClient: new \GuzzleHttp\Client,
    messagingProfileId: env('TELNYX_MESSAGING_PROFILE_ID'),
    agentId: 'e4448a5c...',
    fromNumber: '+15551234567',       // for SMS fallback
);

$adapter->postMessage(
    'telnyx:e4448a5c...:+15559876543',
    PostableMessage::text('Hello via RCS!')
);
```

### RCS (rich card)

```php
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Button;

$card = Card::make()
    ->header('Special Offer')
    ->section(fn ($s) => $s->text('50% off today only'))
    ->image('https://example.com/banner.jpg')
    ->actions([
        Button::primary('Shop Now', 'action_shop'),
        Button::secondary('Dismiss', 'action_dismiss'),
    ]);

$adapter->postMessage(
    'telnyx:e4448a5c...:+15559876543',
    PostableMessage::card($card)
);
```

### RCS fallback

When `from_number` is set and RCS delivery fails, the adapter automatically includes `sms_fallback` and `mms_fallback` in the RCS request so Telnyx can downgrade to SMS/MMS.

## Thread ID Format

| Format | Description |
|--------|-------------|
| `telnyx:{fromNumber}:{toNumber}` | SMS/MMS conversation between two phone numbers |
| `telnyx:{agentId}:{phoneNumber}` | RCS conversation between agent and user |

For SMS/MMS, `fromNumber` is your Telnyx number (the bot), `toNumber` is the remote party.
For RCS, `agentId` is your RCS agent, `phoneNumber` is the user's phone.

## Webhook

Telnyx sends `message.received` events to your configured webhook URL. Signatures are verified using Ed25519 (`telnyx-signature-ed25519` + `telnyx-timestamp` headers) when `public_key` is configured.

Configure the webhook URL on your Messaging Profile (for SMS/MMS) or RCS Agent (for RCS inbound) in the Telnyx Portal.

Other event types (`message.sent`, `message.finalized`, `message.read`) are silently ignored.

### Supported inbound event types

| Type | Payload path |
|------|-------------|
| SMS | `payload.text` + `payload.media[]` (array of `{url, content_type}`) |
| MMS | `payload.text` + `payload.media[]` (array of `{url, content_type}`) |
| RCS text | `payload.body.text` |
| RCS file | `payload.body.user_file.payload` |
| RCS location | `payload.body.location` |
| RCS suggestion | `payload.body.suggestion_response` |

## Feature Matrix

| Feature | Supported |
|---------|-----------|
| Post messages (SMS/MMS) | ✓ |
| Post messages (RCS) | ✓ |
| Edit messages | ✗ |
| Delete messages | ✗ |
| Reactions | ✗ |
| Typing indicator | ✓ (RCS only) |
| Fetch messages | ✗ |
| Fetch thread info | ✓ (minimal) |
| Fetch channel info | ✗ |
| Get user | ✓ (phone number) |
| Open DM | ✓ |
| Stream | ✓ (collects then posts) |

## Notes

- SMS/MMS use `POST /v2/messages`; RCS uses `POST /v2/messages/rcs` (separate endpoint)
- When `agent_id` is configured, outbound messages go through the RCS endpoint with SMS/MMS fallback
- `messaging_profile_id` is **required** for RCS (throws if missing)
- Requires `ext-sodium` for Ed25519 webhook signature verification
- Phone numbers must be E.164 format (`+15551234567`)
- RCS rich cards are auto-converted from the SDK `Card` system (title, description, media, suggestions)

## License

MIT
