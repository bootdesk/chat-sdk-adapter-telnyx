<?php

namespace BootDesk\ChatSDK\Telnyx\Tests;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Exceptions\UnsupportedOperationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Telnyx\TelnyxAdapter;
use Money\Money;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class TelnyxAdapterTest extends TestCase
{
    private TelnyxAdapter $adapter;

    private Psr17Factory $factory;

    /** @var RequestInterface[] */
    private array $capturedRequests = [];

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;
        $this->capturedRequests = [];

        $captured = &$this->capturedRequests;
        $factory = $this->factory;

        $mockClient = new class($captured, $factory) implements ClientInterface
        {
            public function __construct(
                private array &$captured,
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                $uri = (string) $request->getUri();

                if (str_contains($uri, 'messages/rcs')) {
                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream(json_encode([
                            'data' => [
                                'id' => 'rcs-msg-001',
                                'agent_id' => 'agent-123',
                                'to' => '+15559876543',
                                'status' => 'queued',
                            ],
                        ]))
                    );
                }

                if (str_contains($uri, 'messages')) {
                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream(json_encode([
                            'data' => [
                                'id' => 'b0c7e8cb-6227-4c74-9f32-c7f80c30934b',
                                'direction' => 'outbound',
                                'type' => 'SMS',
                                'from' => ['phone_number' => '+15551234567'],
                                'to' => [['phone_number' => '+15559876543', 'status' => 'queued']],
                                'text' => 'Hello, world!',
                                'sent_at' => '2024-01-15T21:32:13.596+00:00',
                            ],
                        ]))
                    );
                }

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode(['data' => []]))
                );
            }
        };

        $this->adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    public function test_get_name(): void
    {
        $this->assertSame('telnyx', $this->adapter->getName());
    }

    public function test_get_bot_user_id(): void
    {
        $this->assertSame('+15551234567', $this->adapter->getBotUserId());
    }

    public function test_thread_id_encoding(): void
    {
        $id = $this->adapter->encodeThreadId(['from' => '+15551234567', 'to' => '+15559876543']);
        $this->assertSame('telnyx:+15551234567:+15559876543', $id);
    }

    public function test_thread_id_decoding(): void
    {
        $decoded = $this->adapter->decodeThreadId('telnyx:+15551234567:+15559876543');
        $this->assertSame('+15551234567', $decoded['from']);
        $this->assertSame('+15559876543', $decoded['to']);
    }

    public function test_channel_id_from_thread(): void
    {
        $this->assertSame('telnyx:+15551234567', $this->adapter->channelIdFromThreadId('telnyx:+15551234567:+15559876543'));
    }

    public function test_parse_sms_webhook(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => '84cca175-9755-4859-b67f-4730d7f58aa3',
                    'direction' => 'inbound',
                    'type' => 'SMS',
                    'from' => ['phone_number' => '+13125550001', 'carrier' => 'T-Mobile'],
                    'to' => [['phone_number' => '+15551234567', 'status' => 'webhook_delivered']],
                    'text' => 'Hello from Telnyx!',
                    'media' => [],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('84cca175-9755-4859-b67f-4730d7f58aa3', $message->id);
        $this->assertSame('telnyx:+15551234567:+13125550001', $message->threadId);
        $this->assertSame('+13125550001', $message->author->id);
        $this->assertSame('Hello from Telnyx!', $message->text);
        $this->assertTrue($message->isDM);
        $this->assertFalse($message->isMention);
    }

    public function test_parse_mms_webhook_with_media(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'mms-001',
                    'direction' => 'inbound',
                    'type' => 'MMS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [['phone_number' => '+15551234567', 'status' => 'webhook_delivered']],
                    'text' => 'Check this photo',
                    'media' => [
                        ['url' => 'https://example.com/photo.jpg', 'content_type' => 'image/jpeg'],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('Check this photo', $message->text);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('https://example.com/photo.jpg', $message->attachments[0]->url);
        $this->assertSame('image/jpeg', $message->attachments[0]->type);
    }

    public function test_parse_rcs_text_webhook(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'rcs-001',
                    'direction' => 'inbound',
                    'type' => 'RCS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [['agent_id' => 'e4448a5c0670c2a9', 'agent_name' => 'My Agent']],
                    'body' => ['text' => 'Hello RCS!'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('Hello RCS!', $message->text);
        $this->assertSame('rcs-001', $message->id);
    }

    public function test_parse_rcs_file_webhook(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'rcs-002',
                    'direction' => 'inbound',
                    'type' => 'RCS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [],
                    'body' => [
                        'user_file' => [
                            'payload' => [
                                'file_name' => 'photo.jpg',
                                'file_size_bytes' => 179099,
                                'file_uri' => 'https://storage.example.com/photo.jpg',
                                'mime_type' => 'image/jpeg',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('https://storage.example.com/photo.jpg', $message->attachments[0]['url']);
        $this->assertSame('image/jpeg', $message->attachments[0]['type']);
        $this->assertSame('photo.jpg', $message->attachments[0]['name']);
    }

    public function test_parse_rcs_suggestion_response(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'rcs-003',
                    'direction' => 'inbound',
                    'type' => 'RCS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [],
                    'body' => [
                        'suggestion_response' => [
                            'postback_data' => 'action_visit_store',
                            'text' => 'Explore the online store',
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertStringContainsString('Explore the online store', $message->text);
        $this->assertStringContainsString('action_visit_store', $message->text);
    }

    public function test_parse_rcs_location_webhook(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'rcs-004',
                    'direction' => 'inbound',
                    'type' => 'RCS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [],
                    'body' => [
                        'location' => [
                            'latitude' => 38.249613,
                            'longitude' => -85.783784,
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertStringContainsString('38.249613', $message->text);
        $this->assertStringContainsString('-85.783784', $message->text);
    }

    public function test_parse_sms_webhook_includes_price(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg-price-001',
                    'direction' => 'inbound',
                    'type' => 'SMS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [['phone_number' => '+15551234567', 'status' => 'webhook_delivered']],
                    'text' => 'Hello',
                    'media' => [],
                    'cost' => ['amount' => '0.0050', 'currency' => 'USD'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertNotNull($message->price);
        $this->assertSame('1', $message->price->getAmount());
        $this->assertSame('USD', $message->price->getCurrency()->getCode());
    }

    public function test_parse_sms_webhook_price_defaults_to_null(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg-no-price',
                    'direction' => 'inbound',
                    'type' => 'SMS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [['phone_number' => '+15551234567', 'status' => 'webhook_delivered']],
                    'text' => 'Hello',
                    'media' => [],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertNull($message->price);
    }

    public function test_parse_rcs_webhook_includes_price(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'rcs-price-001',
                    'direction' => 'inbound',
                    'type' => 'RCS',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [['agent_id' => 'e4448a5c0670c2a9']],
                    'body' => ['text' => 'Hello RCS!'],
                    'cost' => ['amount' => '0.0500', 'currency' => 'USD'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertNotNull($message->price);
        $this->assertSame('5', $message->price->getAmount());
        $this->assertSame('USD', $message->price->getCurrency()->getCode());
    }

    public function test_parse_webhook_ignores_non_received_events(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        $body = json_encode([
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => ['id' => 'x', 'type' => 'SMS'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_message_cost_from_finalized_event(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'msg-001',
                    'from' => ['phone_number' => '+15551234567'],
                    'to' => [['phone_number' => '+15559876543', 'status' => 'delivered']],
                    'cost' => ['amount' => '0.0500', 'currency' => 'USD'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseMessageCost($request);

        $this->assertNotNull($result);
        $this->assertSame(['msg-001'], $result['messageIds']);
        $this->assertSame('telnyx:+15551234567:+15559876543', $result['threadId']);
        $this->assertSame('+15559876543', $result['userId']);
        $this->assertInstanceOf(Money::class, $result['price']);
        $this->assertSame('5', $result['price']->getAmount());
        $this->assertSame('USD', $result['price']->getCurrency()->getCode());
        $this->assertNull($result['originId']);
    }

    public function test_parse_message_cost_from_inbound_event(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg-inbound-001',
                    'from' => ['phone_number' => '+13125550001'],
                    'to' => [['phone_number' => '+15551234567', 'status' => 'webhook_delivered']],
                    'cost' => ['amount' => '0.0050', 'currency' => 'USD'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseMessageCost($request);

        $this->assertNotNull($result);
        $this->assertSame('1', $result['price']->getAmount());
        $this->assertSame('USD', $result['price']->getCurrency()->getCode());
        $this->assertSame('telnyx:+15551234567:+13125550001', $result['threadId']);
        $this->assertSame('+13125550001', $result['userId']);
    }

    public function test_parse_message_cost_returns_null_when_no_cost(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'msg-002',
                    'from' => ['phone_number' => '+15551234567'],
                    'to' => [['phone_number' => '+15559876543', 'status' => 'delivered']],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseMessageCost($request));
    }

    public function test_parse_message_cost_returns_null_for_invalid_json(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream('not json'));

        $this->assertNull($this->adapter->parseMessageCost($request));
    }

    public function test_parse_message_cost_with_eur(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'msg-003',
                    'from' => ['phone_number' => '+15551234567'],
                    'to' => [['phone_number' => '+15559876543', 'status' => 'delivered']],
                    'cost' => ['amount' => '0.1234', 'currency' => 'EUR'],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseMessageCost($request);

        $this->assertNotNull($result);
        $this->assertSame('12', $result['price']->getAmount());
        $this->assertSame('EUR', $result['price']->getCurrency()->getCode());
    }

    public function test_parse_message_cost_handles_rcs_agent_id_thread(): void
    {
        $body = json_encode([
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'rcs-msg-001',
                    'from' => ['agent_id' => 'agent-123'],
                    'to' => [['phone_number' => '+15559876543', 'status' => 'delivered']],
                    'cost' => ['amount' => '0.0100', 'currency' => 'USD'],
                ],
            ],
        ]);

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            agentId: 'agent-123',
            httpClient: $this->createMock(ClientInterface::class),
            psrFactory: $this->factory,
        );

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream($body));

        $result = $adapter->parseMessageCost($request);

        $this->assertNotNull($result);
        $this->assertSame('telnyx:agent-123:+15559876543', $result['threadId']);
        $this->assertSame('1', $result['price']->getAmount());
    }

    public function test_post_sms_message(): void
    {
        $sent = $this->adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello Telnyx')
        );

        $this->assertSame('b0c7e8cb-6227-4c74-9f32-c7f80c30934b', $sent->id);
        $this->assertSame('telnyx:+15551234567:+15559876543', $sent->threadId);
    }

    public function test_post_sms_sends_correct_payload(): void
    {
        $this->adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $this->assertCount(1, $this->capturedRequests);

        $sentBody = json_decode((string) $this->capturedRequests[0]->getBody(), true);
        $this->assertSame('+15551234567', $sentBody['from']);
        $this->assertSame('+15559876543', $sentBody['to']);
        $this->assertSame('Hello', $sentBody['text']);
        $this->assertSame('profile-123', $sentBody['messaging_profile_id']);
        $this->assertStringContainsString('/messages', (string) $this->capturedRequests[0]->getUri());
        $this->assertStringNotContainsString('/messages/rcs', (string) $this->capturedRequests[0]->getUri());
    }

    public function test_post_sms_price_defaults_to_null(): void
    {
        $sent = $this->adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $this->assertNull($sent->price);
    }

    public function test_post_sms_includes_price(): void
    {
        $factory = $this->factory;
        $captured = [];

        $mockClient = new class($captured, $factory) implements ClientInterface
        {
            private array $captured;

            private Psr17Factory $factory;

            public function __construct(array &$captured, Psr17Factory $factory)
            {
                $this->captured = &$captured;
                $this->factory = $factory;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'msg-001',
                            'direction' => 'outbound',
                            'type' => 'SMS',
                            'cost' => [
                                'amount' => '0.0500',
                                'currency' => 'USD',
                            ],
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $this->assertInstanceOf(Money::class, $sent->price);
        $this->assertSame('5', $sent->price->getAmount());
        $this->assertSame('USD', $sent->price->getCurrency()->getCode());
    }

    public function test_post_sms_includes_price_with_eur(): void
    {
        $factory = $this->factory;

        $mockClient = new class($factory) implements ClientInterface
        {
            public function __construct(
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'msg-002',
                            'cost' => [
                                'amount' => '0.1234',
                                'currency' => 'EUR',
                            ],
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $this->assertNotNull($sent->price);
        $this->assertSame('12', $sent->price->getAmount()); // 0.1234 EUR → 12 cents
        $this->assertSame('EUR', $sent->price->getCurrency()->getCode());
    }

    public function test_post_sms_price_ignores_missing_cost(): void
    {
        $factory = $this->factory;

        $mockClient = new class($factory) implements ClientInterface
        {
            public function __construct(
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'msg-003',
                            // no cost key
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $this->assertNull($sent->price);
    }

    public function test_post_rcs_message_uses_rcs_endpoint(): void
    {
        $adapter = $this->createRcsAdapter();

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello RCS')
        );

        $this->assertSame('rcs-msg-001', $sent->id);
    }

    public function test_post_rcs_sends_correct_payload(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured);

        $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello RCS')
        );

        $this->assertCount(1, $captured);

        $uri = (string) $captured[0]->getUri();
        $this->assertStringContainsString('/messages/rcs', $uri);

        $sentBody = json_decode((string) $captured[0]->getBody(), true);
        $this->assertSame('agent-123', $sentBody['agent_id']);
        $this->assertSame('+15559876543', $sentBody['to']);
        $this->assertSame('Hello RCS', $sentBody['agent_message']['content_message']['text']);
        $this->assertSame('profile-123', $sentBody['messaging_profile_id']);
    }

    public function test_post_rcs_includes_sms_fallback(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured);

        $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $sentBody = json_decode((string) $captured[0]->getBody(), true);
        $this->assertSame('+15551234567', $sentBody['sms_fallback']['from']);
        $this->assertSame('Hello', $sentBody['sms_fallback']['text']);
    }

    public function test_post_rcs_includes_mms_fallback_with_attachments(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured);

        $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            new PostableMessage(
                content: 'Photo',
                attachments: [new Attachment(url: 'https://example.com/photo.jpg', type: 'image/jpeg')],
            )
        );

        $sentBody = json_decode((string) $captured[0]->getBody(), true);
        $this->assertSame('Photo', $sentBody['sms_fallback']['text']);
        $this->assertArrayHasKey('mms_fallback', $sentBody);
        $this->assertSame('+15551234567', $sentBody['mms_fallback']['from']);
        $this->assertSame('Photo', $sentBody['mms_fallback']['text']);
        $this->assertContains('https://example.com/photo.jpg', $sentBody['mms_fallback']['media_urls']);
    }

    public function test_post_rcs_media_attachment_uses_content_info(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured);

        $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            new PostableMessage(
                content: 'Check this',
                attachments: [new Attachment(url: 'https://example.com/file.pdf', type: 'application/pdf')],
            )
        );

        $sentBody = json_decode((string) $captured[1]->getBody(), true);
        $this->assertSame('https://example.com/file.pdf', $sentBody['agent_message']['content_message']['content_info']['file_url']);
    }

    public function test_post_rcs_card_sends_rich_card(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured);

        $card = Card::make()
            ->header('Event Invitation')
            ->section(fn ($s) => $s->text('Join us for the annual conference'))
            ->image('https://example.com/banner.jpg')
            ->actions([
                Button::primary('Accept', 'action_accept'),
                Button::secondary('Decline', 'action_decline'),
            ]);

        $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::card($card)
        );

        $sentBody = json_decode((string) $captured[0]->getBody(), true);
        $richCard = $sentBody['agent_message']['content_message']['rich_card'];
        $cardContent = $richCard['standalone_card']['card_content'];

        $this->assertSame('Event Invitation', $cardContent['title']);
        $this->assertSame('Join us for the annual conference', $cardContent['description']);
        $this->assertSame('https://example.com/banner.jpg', $cardContent['media']['file_url']);
        $this->assertCount(2, $cardContent['suggestions']);
        $this->assertSame('Accept', $cardContent['suggestions'][0]['reply']['text']);
        $this->assertSame('action_accept', $cardContent['suggestions'][0]['reply']['postback_data']);
        $this->assertSame('Decline', $cardContent['suggestions'][1]['reply']['text']);
    }

    public function test_post_rcs_no_fallback_without_from_number(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured, fromNumber: null);

        $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $sentBody = json_decode((string) $captured[0]->getBody(), true);
        $this->assertArrayNotHasKey('sms_fallback', $sentBody);
        $this->assertArrayNotHasKey('mms_fallback', $sentBody);
    }

    public function test_edit_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('does not support editing');

        $this->adapter->editMessage(
            'telnyx:+15551234567:+15559876543',
            'msg-123',
            PostableMessage::text('Updated')
        );
    }

    public function test_delete_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('does not support deleting');

        $this->adapter->deleteMessage('telnyx:+15551234567:+15559876543', 'msg-123');
    }

    public function test_fetch_messages_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('does not support fetching');

        $this->adapter->fetchMessages('telnyx:+15551234567:+15559876543');
    }

    public function test_add_reaction_is_noop(): void
    {
        $this->adapter->addReaction('telnyx:+15551234567:+15559876543', 'msg-123', 'thumbsup');
        $this->assertTrue(true);
    }

    public function test_remove_reaction_is_noop(): void
    {
        $this->adapter->removeReaction('telnyx:+15551234567:+15559876543', 'msg-123', 'thumbsup');
        $this->assertTrue(true);
    }

    public function test_start_typing_is_noop_without_agent_id(): void
    {
        $this->adapter->startTyping('telnyx:+15551234567:+15559876543');
        $this->assertCount(0, $this->capturedRequests);
    }

    public function test_start_typing_sends_rcs_event_with_agent_id(): void
    {
        $captured = [];
        $adapter = $this->createRcsAdapterWithCapture($captured);

        $adapter->startTyping('telnyx:+15551234567:+15559876543');

        $this->assertCount(1, $captured);
        $sentBody = json_decode((string) $captured[0]->getBody(), true);
        $this->assertSame('agent-123', $sentBody['agent_id']);
        $this->assertSame('+15559876543', $sentBody['to']);
        $this->assertSame('IS_TYPING', $sentBody['agent_message']['event']['event_type']);
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('telnyx:+15551234567:+15559876543');

        $this->assertSame('telnyx:+15551234567:+15559876543', $info->id);
        $this->assertSame('+15551234567', $info->channelId);
    }

    public function test_fetch_channel_info_returns_null(): void
    {
        $this->assertNull($this->adapter->fetchChannelInfo('+15551234567'));
    }

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('+15559876543');

        $this->assertSame('+15559876543', $user->id);
        $this->assertSame('+15559876543', $user->name);
    }

    public function test_open_dm(): void
    {
        $threadId = $this->adapter->openDM('+15559876543');

        $this->assertSame('telnyx:+15551234567:+15559876543', $threadId);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_initialize_is_noop(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);
        $this->assertTrue(true);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream(
            'telnyx:+15551234567:+15559876543',
            ['Hello ', 'world', '!'],
        );

        $this->assertNotNull($sent);
        $this->assertSame('b0c7e8cb-6227-4c74-9f32-c7f80c30934b', $sent->id);
    }

    public function test_stream_returns_empty_on_no_content(): void
    {
        $sent = $this->adapter->stream(
            'telnyx:+15551234567:+15559876543',
            [],
        );

        $this->assertNull($sent);
    }

    public function test_verify_webhook_without_public_key(): void
    {
        $adapter = new TelnyxAdapter(
            apiKey: 'test_key',
            httpClient: $this->createMock(ClientInterface::class),
        );

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx');
        $result = $adapter->verifyWebhook($request);

        $this->assertNull($result);
    }

    public function test_verify_webhook_rejects_bad_signature(): void
    {
        $adapter = new TelnyxAdapter(
            apiKey: 'test_key',
            publicKey: base64_encode(str_repeat("\x00", 32)),
            httpClient: $this->createMock(ClientInterface::class),
        );

        $body = '{"data":{"event_type":"message.received"}}';
        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withHeader('telnyx-signature-ed25519', base64_encode(str_repeat("\x00", 64)))
            ->withHeader('telnyx-timestamp', (string) time())
            ->withBody($this->factory->createStream($body));

        $this->expectException(AdapterException::class);
        $adapter->verifyWebhook($request);
    }

    public function test_verify_webhook_missing_headers(): void
    {
        $adapter = new TelnyxAdapter(
            apiKey: 'test_key',
            publicKey: base64_encode(str_repeat("\x00", 32)),
            httpClient: $this->createMock(ClientInterface::class),
        );

        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream('{"data":{}}'));

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Missing');

        $adapter->verifyWebhook($request);
    }

    public function test_create_response_returns_null(): void
    {
        $this->assertNull($this->adapter->createResponse());
    }

    public function test_parse_invalid_json_throws(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/telnyx')
            ->withBody($this->factory->createStream('not json'));

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->adapter->parseWebhook($request);
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(401);
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'bad_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('telnyx:+15551234567:+15559876543', PostableMessage::text('test'));
    }

    public function test_post_sms_includes_raw_response(): void
    {
        $sent = $this->adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello SMS')
        );

        $this->assertNotNull($sent->raw);
        $this->assertIsArray($sent->raw);
        // raw is an array of all responses (one for SMS)
        $this->assertCount(1, $sent->raw);
        $this->assertArrayHasKey('data', $sent->raw[0]);
        $this->assertSame('b0c7e8cb-6227-4c74-9f32-c7f80c30934b', $sent->raw[0]['data']['id']);
    }

    public function test_post_rcs_includes_raw_response(): void
    {
        $adapter = $this->createRcsAdapter();

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello RCS')
        );

        $this->assertNotNull($sent->raw);
        $this->assertIsArray($sent->raw);
        // RCS returns a single-element array for one call
        $this->assertCount(1, $sent->raw);
        $this->assertSame('rcs-msg-001', $sent->raw[0]['data']['id']);
    }

    public function test_post_rcs_with_attachments_returns_additional_messages(): void
    {
        $factory = $this->factory;
        $callCount = 0;

        $mockClient = new class($factory, $callCount) implements ClientInterface
        {
            private Psr17Factory $factory;

            private int $callCount;

            public function __construct(Psr17Factory $factory, int &$callCount)
            {
                $this->factory = $factory;
                $this->callCount = &$callCount;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->callCount++;

                // Return a unique ID per call so we can verify each SentMessage
                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'rcs-msg-'.sprintf('%03d', $this->callCount),
                            'agent_id' => 'agent-123',
                            'to' => '+15559876543',
                            'status' => 'queued',
                            'sent_at' => '2024-01-15T21:32:1'.(9 + $this->callCount).'.596+00:00',
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            agentId: 'agent-123',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            new PostableMessage(
                content: 'Check this',
                attachments: [
                    new Attachment(url: 'https://example.com/photo.jpg', type: 'image/jpeg'),
                    new Attachment(url: 'https://example.com/doc.pdf', type: 'application/pdf'),
                ],
            )
        );

        // 1 text message + 2 attachments = 3 API calls
        $this->assertCount(2, $sent->additionalMessages);
        $this->assertSame('rcs-msg-001', $sent->id);
        $this->assertSame('rcs-msg-002', $sent->additionalMessages[0]->id);
        $this->assertSame('rcs-msg-003', $sent->additionalMessages[1]->id);
    }

    public function test_post_rcs_with_attachments_sets_raw_responses(): void
    {
        $factory = $this->factory;
        $callCount = 0;

        $mockClient = new class($factory, $callCount) implements ClientInterface
        {
            private Psr17Factory $factory;

            private int $callCount;

            public function __construct(Psr17Factory $factory, int &$callCount)
            {
                $this->factory = $factory;
                $this->callCount = &$callCount;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->callCount++;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'rcs-msg-'.sprintf('%03d', $this->callCount),
                            'status' => 'queued',
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            agentId: 'agent-123',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            new PostableMessage(
                content: 'Files',
                attachments: [
                    new Attachment(url: 'https://example.com/file.pdf', type: 'application/pdf'),
                ],
            )
        );

        // raw should be an array of all responses
        $this->assertIsArray($sent->raw);
        $this->assertCount(2, $sent->raw);
        $this->assertSame('rcs-msg-001', $sent->raw[0]['data']['id']);
        $this->assertSame('rcs-msg-002', $sent->raw[1]['data']['id']);

        // additionalMessages should each have their own raw
        $this->assertCount(1, $sent->additionalMessages);
        $this->assertIsArray($sent->additionalMessages[0]->raw);
        $this->assertSame('rcs-msg-002', $sent->additionalMessages[0]->raw['data']['id']);
    }

    public function test_post_rcs_text_only_returns_no_additional_messages(): void
    {
        $adapter = $this->createRcsAdapter();

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Just text')
        );

        $this->assertSame([], $sent->additionalMessages);
    }

    public function test_post_rcs_price_defaults_to_null(): void
    {
        $adapter = $this->createRcsAdapter();

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello')
        );

        $this->assertNull($sent->price);
    }

    public function test_post_rcs_includes_price(): void
    {
        $factory = $this->factory;

        $mockClient = new class($factory) implements ClientInterface
        {
            public function __construct(
                private Psr17Factory $factory,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'rcs-msg-001',
                            'agent_id' => 'agent-123',
                            'to' => '+15559876543',
                            'status' => 'queued',
                            'sent_at' => '2024-01-15T21:32:19.596+00:00',
                            'cost' => [
                                'amount' => '0.0325',
                                'currency' => 'USD',
                            ],
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            agentId: 'agent-123',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            PostableMessage::text('Hello RCS')
        );

        $this->assertInstanceOf(Money::class, $sent->price);
        $this->assertSame('3', $sent->price->getAmount()); // 0.0325 USD → 3 cents
        $this->assertSame('USD', $sent->price->getCurrency()->getCode());
    }

    public function test_post_rcs_includes_price_for_additional_messages(): void
    {
        $factory = $this->factory;
        $callCount = 0;

        $mockClient = new class($factory, $callCount) implements ClientInterface
        {
            public function __construct(
                private Psr17Factory $factory,
                private int &$callCount,
            ) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->callCount++;

                $prices = [
                    1 => ['amount' => '0.0500', 'currency' => 'USD'],
                    2 => ['amount' => '0.0325', 'currency' => 'USD'],
                ];
                $price = $prices[$this->callCount] ?? ['amount' => '0.0100', 'currency' => 'USD'];

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'rcs-msg-'.sprintf('%03d', $this->callCount),
                            'agent_id' => 'agent-123',
                            'to' => '+15559876543',
                            'status' => 'queued',
                            'sent_at' => '2024-01-15T21:32:19.596+00:00',
                            'cost' => $price,
                        ],
                    ]))
                );
            }
        };

        $adapter = new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: '+15551234567',
            agentId: 'agent-123',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $sent = $adapter->postMessage(
            'telnyx:+15551234567:+15559876543',
            new PostableMessage(
                content: 'Hello RCS',
                attachments: [
                    new Attachment(type: 'image', url: 'https://example.com/img.png'),
                ],
            )
        );

        // Primary message: 0.0500 USD → 5 cents
        $this->assertNotNull($sent->price);
        $this->assertSame('5', $sent->price->getAmount());
        $this->assertSame('USD', $sent->price->getCurrency()->getCode());

        // Additional message: 0.0325 USD → 3 cents (truncated to 2 decimal places)
        $this->assertCount(1, $sent->additionalMessages);
        $this->assertNotNull($sent->additionalMessages[0]->price);
        $this->assertSame('3', $sent->additionalMessages[0]->price->getAmount());
        $this->assertSame('USD', $sent->additionalMessages[0]->price->getCurrency()->getCode());
    }

    private function createRcsAdapter(): TelnyxAdapter
    {
        $captured = [];

        return $this->createRcsAdapterWithCapture($captured);
    }

    private function createRcsAdapterWithCapture(array &$captured, ?string $fromNumber = '+15551234567'): TelnyxAdapter
    {
        $factory = $this->factory;
        $captured = &$captured;

        $mockClient = new class($captured, $factory) implements ClientInterface
        {
            private array $captured;

            private Psr17Factory $factory;

            public function __construct(array &$captured, Psr17Factory $factory)
            {
                $this->captured = &$captured;
                $this->factory = $factory;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'data' => [
                            'id' => 'rcs-msg-001',
                            'agent_id' => 'agent-123',
                            'to' => '+15559876543',
                            'status' => 'queued',
                        ],
                    ]))
                );
            }
        };

        return new TelnyxAdapter(
            apiKey: 'test_api_key',
            messagingProfileId: 'profile-123',
            fromNumber: $fromNumber,
            agentId: 'agent-123',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }
}
