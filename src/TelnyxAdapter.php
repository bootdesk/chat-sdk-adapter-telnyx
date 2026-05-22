<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telnyx;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\HandlesStatuses;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Exceptions\RateLimitException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TelnyxAdapter implements Adapter, HandlesSlashCommands, HandlesStatuses
{
    private const ADAPTER_NAME = 'telnyx-chat-sdk-php';

    private const ADAPTER_VERSION = '0.2.5';

    private const USER_AGENT = self::ADAPTER_NAME.'/'.self::ADAPTER_VERSION;

    protected TelnyxFormatConverter $formatConverter;

    protected ?TelnyxWebhookVerifier $webhookVerifier = null;

    protected FileUploadConverter $fileUploadConverter;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly ClientInterface $httpClient,
        protected readonly ?string $messagingProfileId = null,
        ?string $publicKey = null,
        protected readonly ?string $fromNumber = null,
        protected readonly ?string $agentId = null,
        protected readonly string $apiUrl = 'https://api.telnyx.com/v2/',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
        protected readonly array $extraTags = [],
        protected readonly bool $disableAttributionTags = false,
    ) {
        $this->formatConverter = new TelnyxFormatConverter;
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;

        if ($publicKey !== null) {
            $this->webhookVerifier = new TelnyxWebhookVerifier($publicKey);
        }
    }

    public function getName(): string
    {
        return 'telnyx';
    }

    public function getBotUserId(): ?string
    {
        return $this->fromNumber;
    }

    public function parseStatus(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $event = $payload['data'] ?? [];
        $eventType = $event['event_type'] ?? '';
        $p = $event['payload'] ?? [];

        // Only handle outbound status events
        if ($eventType === 'message.received') {
            return null;
        }

        $recipient = $p['to'][0] ?? [];
        $status = $recipient['status'] ?? '';
        $phoneNumber = $recipient['phone_number'] ?? '';

        if ($phoneNumber === '' || $status === '') {
            return null;
        }

        $common = [
            'messageIds' => [$p['id'] ?? ''],
            'threadId' => $this->encodeThreadId([
                'from' => $p['from']['agent_id'] ?? $p['from']['phone_number'] ?? $this->fromNumber ?? '',
                'to' => $phoneNumber,
            ]),
            'userId' => $phoneNumber,
            'timestamp' => strtotime($p['completed_at'] ?? $event['occurred_at'] ?? '') ?: null,
            'raw' => $payload,
            'originId' => null,
        ];

        if ($status === 'delivered') {
            return ['type' => 'delivered', ...$common];
        }

        if ($status === 'read') {
            return ['type' => 'read', ...$common];
        }

        if (in_array($status, ['delivery_failed', 'sending_failed', 'delivery_unconfirmed'], true)) {
            return ['type' => 'failed', ...$common];
        }

        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $event = $payload['data'] ?? [];
        $eventType = $event['event_type'] ?? '';

        if ($eventType !== 'message.received') {
            return null;
        }

        $p = $event['payload'] ?? [];
        $type = strtoupper($p['type'] ?? '');
        $isRcs = $type === 'RCS';

        $text = $isRcs
            ? ($p['body']['text'] ?? '')
            : ($p['text'] ?? '');

        // Skip RCS non-message events
        if ($isRcs && isset($p['body']['event_type']) && $p['body']['event_type'] !== '') {
            return null;
        }

        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        $fromPhone = $p['from']['phone_number'] ?? '';
        $toEntry = $p['to'][0] ?? [];

        if ($isRcs) {
            $toPhone = is_array($toEntry) ? ($toEntry['agent_id'] ?? '') : '';
        } else {
            $toPhone = is_array($toEntry) ? ($toEntry['phone_number'] ?? '') : '';
        }

        if ($fromPhone === '') {
            return null;
        }

        $threadId = $this->encodeThreadId([
            'from' => $toPhone,
            'to' => $fromPhone,
        ]);

        $parts = explode(' ', $text, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        return [
            'author' => new Author(id: $fromPhone),
            'command' => $command,
            'text' => $args,
            'userId' => $fromPhone,
            'isBot' => false,
            'isMe' => false,
            'channelId' => $threadId,
            'triggerId' => null,
            'raw' => $body,
        ];
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($this->webhookVerifier instanceof TelnyxWebhookVerifier) {
            $this->webhookVerifier->verify($request, (string) $request->getBody());
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null) {
            throw new AdapterException('Invalid JSON payload from Telnyx');
        }

        $event = $payload['data'] ?? $payload;
        $eventType = $event['event_type'] ?? '';
        $p = $event['payload'] ?? [];

        if ($eventType !== 'message.received') {
            return new Message(
                id: $event['id'] ?? '',
                threadId: '',
                author: new Author(id: '', isMe: true),
                text: '',
                formatted: $this->formatConverter->toAst(''),
                isMention: false,
                isDM: false,
                raw: $body,
            );
        }

        $type = $p['type'] ?? 'SMS';
        $isRcs = strtoupper($type) === 'RCS';

        if ($isRcs) {
            return $this->parseRcsMessage($p, $body);
        }

        return $this->parseSmsMessage($p, $body);
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $from = $platformData['from'] ?? '';
        $to = $platformData['to'] ?? '';

        return "telnyx:{$from}:{$to}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 3);

        return [
            'from' => $parts[1] ?? '',
            'to' => $parts[2] ?? '',
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $this->decodeThreadId($threadId)['from'];
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);

        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        if ($this->agentId !== null) {
            $response = $this->sendRcs($decoded['to'], $message);
        } else {
            $response = $this->sendSms($decoded['from'], $decoded['to'], $message);
        }

        $data = $response['data'] ?? $response;

        return new SentMessage(
            id: $data['id'] ?? '',
            threadId: $threadId,
            timestamp: $data['sent_at'] ?? null,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        throw new AdapterException('Telnyx does not support editing messages');
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        throw new AdapterException('Telnyx does not support deleting messages');
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        // Telnyx does not support reactions
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        // Telnyx does not support reactions
    }

    public function startTyping(string $threadId): void
    {
        if ($this->agentId === null) {
            return;
        }

        $messagingProfileId = $this->messagingProfileId
            ?? throw new AdapterException('messaging_profile_id is required for RCS typing indicator');

        $decoded = $this->decodeThreadId($threadId);

        $this->apiCall('messages/rcs', [
            'agent_id' => $this->agentId,
            'to' => $decoded['to'],
            'messaging_profile_id' => $messagingProfileId,
            'agent_message' => [
                'event' => ['event_type' => 'IS_TYPING'],
            ],
        ]);
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        throw new AdapterException('Telnyx does not support fetching message history');
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        return new ThreadInfo(
            id: $threadId,
            channelId: $decoded['from'],
            messageCount: 0,
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        return null;
    }

    public function getUser(string $userId): ?UserInfo
    {
        return new UserInfo(
            id: $userId,
            name: $userId,
        );
    }

    public function openDM(string $userId): ?string
    {
        $from = $this->fromNumber ?? '';

        return $from !== '' ? $this->encodeThreadId(['from' => $from, 'to' => $userId]) : null;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        // No initialization needed
    }

    public function disconnect(): void
    {
        // No persistent connection to close
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function parseSmsMessage(array $p, string $rawBody): Message
    {
        $fromPhone = $p['from']['phone_number'] ?? '';
        $toEntry = $p['to'][0] ?? [];
        $toPhone = $toEntry['phone_number'] ?? '';

        $text = $p['text'] ?? '';
        $media = $p['media'] ?? [];
        $messageId = $p['id'] ?? '';

        $attachments = [];
        foreach ($media as $m) {
            $url = is_array($m) ? ($m['url'] ?? '') : $m;
            if ($url !== '') {
                $attachments[] = new Attachment(
                    type: is_array($m) ? ($m['content_type'] ?? 'media') : 'media',
                    url: $url,
                    mimeType: $m['content_type']
                );
            }
        }

        $threadId = $this->encodeThreadId([
            'from' => $toPhone,
            'to' => $fromPhone,
        ]);

        return new Message(
            id: $messageId,
            threadId: $threadId,
            author: new Author(id: $fromPhone),
            text: $text,
            formatted: $this->formatConverter->toAst($text),
            attachments: $attachments,
            isMention: false,
            isDM: true,
            raw: $rawBody,
        );
    }

    protected function parseRcsMessage(array $p, string $rawBody): Message
    {
        $fromPhone = $p['from']['phone_number'] ?? '';
        $toEntry = $p['to'][0] ?? [];
        $toPhone = is_array($toEntry) ? ($toEntry['phone_number'] ?? $toEntry['agent_id'] ?? '') : '';

        $body = $p['body'] ?? [];
        $messageId = $p['id'] ?? '';

        // Non-message events like is_typing, read, etc.
        if (isset($body['event_type']) && $body['event_type'] !== '') {
            return new Message(
                id: $messageId,
                threadId: '',
                author: new Author(id: '', isMe: true),
                text: '',
                raw: $rawBody,
            );
        }

        $text = $body['text'] ?? '';

        // Append suggestion response text
        if (isset($body['suggestion_response'])) {
            $postback = $body['suggestion_response']['postback_data'] ?? '';
            $label = $body['suggestion_response']['text'] ?? '';
            $text = ($text !== '' ? $text."\n" : '')."[{$label}] ({$postback})";
        }

        // Append location
        if (isset($body['location'])) {
            $lat = $body['location']['latitude'] ?? '';
            $lng = $body['location']['longitude'] ?? '';
            $text = ($text !== '' ? $text."\n" : '')."Location: {$lat}, {$lng}";
        }

        $attachments = [];
        if (isset($body['user_file'])) {
            $file = $body['user_file']['payload'] ?? $body['user_file'];
            $attachments[] = [
                'url' => $file['file_uri'] ?? '',
                'type' => $file['mime_type'] ?? 'application/octet-stream',
                'name' => $file['file_name'] ?? '',
                'size' => $file['file_size_bytes'] ?? 0,
            ];
        }

        $threadId = $this->encodeThreadId([
            'from' => $toPhone,
            'to' => $fromPhone,
        ]);

        return new Message(
            id: $messageId,
            threadId: $threadId,
            author: new Author(id: $fromPhone),
            text: $text,
            formatted: $this->formatConverter->toAst($text),
            attachments: $attachments,
            isMention: false,
            isDM: true,
            raw: $rawBody,
        );
    }

    protected function sendSms(string $from, string $to, PostableMessage $message): array
    {
        $params = $this->buildSmsParams($message);
        $params['from'] = $from;
        $params['to'] = $to;

        if ($this->messagingProfileId !== null) {
            $params['messaging_profile_id'] = $this->messagingProfileId;
        }

        $tags = $this->buildTags();
        if ($tags !== []) {
            $params['tags'] = $tags;
        }

        return $this->apiCall('messages', $params);
    }

    protected function sendRcs(string $to, PostableMessage $message): array
    {
        $messagingProfileId = $this->messagingProfileId
            ?? throw new AdapterException('messaging_profile_id is required for RCS messaging');

        $text = $message->getTextContent();
        $rcsText = mb_substr($text, 0, 3072);
        $attachmentUrls = array_map(
            fn (Attachment $att): string => $att->url,
            array_filter($message->attachments, fn (Attachment $att): bool => $att->url !== ''),
        );

        $responses = [];

        if ($message->isCard()) {
            $responses[] = $this->sendRcsContent(
                to: $to,
                messagingProfileId: $messagingProfileId,
                content: $this->buildRcsCardContent($message->content),
                fallbackText: $text,
                attachmentUrls: $attachmentUrls,
                first: true
            );
        } elseif ($rcsText !== '') {
            $responses[] = $this->sendRcsContent(
                to: $to,
                messagingProfileId: $messagingProfileId,
                content: ['text' => $rcsText],
                fallbackText: $text,
                attachmentUrls: $attachmentUrls,
                first: true
            );
        }

        foreach ($message->attachments as $att) {
            if ($att->url === '') {
                continue;
            }

            $responses[] = $this->sendRcsContent(
                to: $to,
                messagingProfileId: $messagingProfileId,
                content: [
                    'content_info' => [
                        'file_url' => $att->url,
                    ],
                ],
                first: false
            );
        }

        $last = end($responses);

        return $last !== false ? $last : [];
    }

    protected function sendRcsContent(string $to, string $messagingProfileId, array $content, ?string $fallbackText = null, array $attachmentUrls = [], bool $first = false): array
    {
        $params = [
            'agent_id' => $this->agentId,
            'to' => $to,
            'messaging_profile_id' => $messagingProfileId,
            'agent_message' => ['content_message' => $content],
        ];

        if ($first && $this->fromNumber !== null && $fallbackText !== null) {
            $params['sms_fallback'] = [
                'from' => $this->fromNumber,
                'text' => $fallbackText,
            ];

            if ($attachmentUrls !== []) {
                $params['mms_fallback'] = [
                    'from' => $this->fromNumber,
                    'text' => $fallbackText,
                    'media_urls' => array_values($attachmentUrls),
                ];
            }
        }

        $tags = $this->buildTags();
        if ($tags !== []) {
            $params['tags'] = $tags;
        }

        return $this->apiCall('messages/rcs', $params);
    }

    protected function buildSmsParams(PostableMessage $message): array
    {
        $params = [];

        $params['text'] = $message->isCard() ? $message->content->getFallbackText() : $message->getTextContent();

        if ($message->attachments !== []) {
            $params['media_urls'] = array_map(
                fn (Attachment $att): string => $att->url ?? '',
                $message->attachments,
            );
        }

        return $params;
    }

    protected function buildRcsCardContent(Card $card): array
    {
        $cardContent = [];

        if ($card->getHeader() !== null) {
            $cardContent['title'] = $card->getHeader();
        }

        $descriptions = [];
        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $descriptions[] = $section->getText();
            }
        }
        foreach ($card->getTables() as $table) {
            $lines = [implode(' | ', $table->headers)];
            foreach ($table->rows as $row) {
                $lines[] = implode(' | ', $row);
            }
            $descriptions[] = implode("\n", $lines);
        }
        if ($descriptions !== []) {
            $cardContent['description'] = mb_substr(implode("\n\n", $descriptions), 0, 2000);
        }

        $images = $card->getImages();
        if ($images !== []) {
            $cardContent['media'] = ['file_url' => $images[0]->url];
        }

        $buttons = $card->getButtons();
        if ($buttons !== []) {
            $suggestions = [];
            foreach ($buttons as $button) {
                $suggestions[] = [
                    'reply' => [
                        'text' => $button->label,
                        'postback_data' => $button->actionId,
                    ],
                ];
            }
            $cardContent['suggestions'] = $suggestions;
        }

        return [
            'rich_card' => [
                'standalone_card' => [
                    'card_content' => $cardContent,
                ],
            ],
        ];
    }

    protected function apiCall(string $endpoint, array $params): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        $body = json_encode(array_filter($params, fn ($v): bool => $v !== null && $v !== []));
        $request = $factory->createRequest('POST', $this->apiUrl.$endpoint)
            ->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withBody($factory->createStream($body));

        $psrResponse = $this->httpClient->sendRequest($request);
        $statusCode = $psrResponse->getStatusCode();
        $responseBody = (string) $psrResponse->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMsg = "Telnyx API returned HTTP {$statusCode} for {$endpoint}: {$responseBody}";

            if ($statusCode === 429) {
                $retryAfter = $psrResponse->getHeaderLine('retry-after');
                $retryAfterInt = $retryAfter !== '' ? (int) $retryAfter : null;

                throw new RateLimitException(
                    $errorMsg,
                    $statusCode,
                    previous: null,
                    retryAfter: $retryAfterInt
                );
            }

            if (in_array($statusCode, [401, 403], true)) {
                throw new AuthenticationException($errorMsg);
            }

            throw new AdapterException($errorMsg);
        }

        $data = json_decode($responseBody, true);

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Telnyx API: {$endpoint}");
        }

        $errors = $data['errors'] ?? [];
        if ($errors !== []) {
            $error = $errors[0] ?? [];
            $code = $error['code'] ?? 'unknown';
            $detail = $error['detail'] ?? ($error['title'] ?? 'unknown error');

            if (in_array($code, ['401', '403', 'unauthorized', 'forbidden'], true)) {
                throw new AuthenticationException("Telnyx API authentication error ({$endpoint}): [{$code}] {$detail}");
            }

            throw new AdapterException("Telnyx API error ({$endpoint}): [{$code}] {$detail}");
        }

        return $data;
    }

    private function buildTags(): array
    {
        if ($this->disableAttributionTags) {
            return $this->extraTags;
        }

        $attributionTags = [
            self::ADAPTER_NAME,
            'v'.self::ADAPTER_VERSION,
        ];

        return array_merge($attributionTags, $this->extraTags);
    }
}
