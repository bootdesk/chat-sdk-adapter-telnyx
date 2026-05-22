# bootdesk/chat-sdk-adapter-telnyx

Telnyx adapter for the BootDesk multi-platform messaging SDK. Supports SMS, MMS, and RCS.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-telnyx
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable               | Description                                                            | Example                             |
| ---------------------- | ---------------------------------------------------------------------- | ----------------------------------- |
| `api_key`              | Telnyx API V2 Key                                                      | `KEY...`                            |
| `http_client`          | PSR-18 HTTP client instance                                            | `new GuzzleHttp\Client`             |
| `messaging_profile_id` | Messaging Profile UUID                                                 | `16fd2706-...`                      |
| `public_key`           | Ed25519 public key for webhook verification                            | `base64...`                         |
| `from_number`          | Sender ID — +E.164 phone number, alphanumeric sender ID, or short code | `+15551234567`, `MyBrand`, `123456` |
| `agent_id`             | RCS agent ID (enables RCS sending)                                     | `e4448a5c...`                       |

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

When `from_number` is set and the first RCS message includes `sms_fallback` / `mms_fallback`, Telnyx **may choose to send via SMS/MMS even if the recipient has RCS enabled** — it depends on the carrier's capabilities at that moment. This means you might see RCS-fallback messages delivered as SMS for no apparent reason.

If you need strict RCS-only delivery (e.g., for rich cards, suggestions, or branding), register **two separate Telnyx adapters**:

```php
$rcsAdapter = new TelnyxAdapter(
    apiKey: env('TELNYX_API_KEY'),
    httpClient: $client,
    messagingProfileId: env('TELNYX_MESSAGING_PROFILE_ID'),
    fromNumber: env('TELNYX_FROM_NUMBER'),
    agentId: env('TELNYX_RCS_AGENT_ID'),
    // No fromNumber — no fallback, RCS-only
);
$smsAdapter = new TelnyxAdapter(
    apiKey: env('TELNYX_API_KEY'),
    httpClient: $client,
    messagingProfileId: env('TELNYX_MESSAGING_PROFILE_ID'),
    fromNumber: env('TELNYX_FROM_NUMBER'),
    // No agentId — SMS/MMS-only
);

$chat->registerAdapter('rcs', $rcsAdapter);
$chat->registerAdapter('sms', $smsAdapter);
```

Then route based on `$statusData['type'] === 'failed'` in your `onMessageFailed` handler: retry via the SMS adapter.

### RCS delivery reliability

RCS is a flaky protocol. Even when Telnyx accepts an RCS message (returns 200), the carrier may silently reject it or fail to deliver it. This is returned as a `delivery_failed` status event via `HandlesStatuses`. Key points:

- `delivery_failed` is **normal** and expected — do not treat it as a bug
- Always implement the `onMessageFailed` handler to trigger SMS fallback
- Some carriers/regions have no RCS support at all — all RCS messages will fail there
- Users may have RCS disabled on their device — messages fall back silently
- Read receipts (`message.read`) are **not guaranteed** — some users disable them
- SMS fallback (`from_number`) is recommended for production use

## Feature Matrix

| Feature            | Supported |
| ------------------ | --------- |
| Post messages      | ✓         |
| Edit messages      | ✗         |
| Delete messages    | ✗         |
| Reactions          | ✗         |
| Slash commands     | ✓         |
| Typing indicator   | ✗         |
| Fetch messages     | ✗         |
| Fetch thread info  | ✗         |
| Fetch channel info | ✗         |
| Get user           | ✗         |
| Open DM            | ✗         |
| Stream             | ✓         |

## Documentationn

Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
