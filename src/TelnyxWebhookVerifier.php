<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telnyx;

use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use Psr\Http\Message\ServerRequestInterface;

class TelnyxWebhookVerifier
{
    private const TIMESTAMP_MAX_AGE_SECONDS = 300;

    public function __construct(
        private readonly string $publicKey,
    ) {}

    public function verify(ServerRequestInterface $request, string $body): void
    {
        $signature = $request->getHeaderLine('telnyx-signature-ed25519');
        $timestamp = $request->getHeaderLine('telnyx-timestamp');

        if ($signature === '' || $timestamp === '') {
            throw new AdapterException('Missing Telnyx webhook signature headers');
        }

        $timestampInt = (int) $timestamp;
        $now = time();

        if (abs($now - $timestampInt) > self::TIMESTAMP_MAX_AGE_SECONDS) {
            throw new AdapterException('Stale timestamp - webhook timestamp too old or in future');
        }

        $payload = $timestamp.'|'.$body;
        $publicKey = base64_decode($this->publicKey, true);

        if ($publicKey === false) {
            throw new AdapterException('Invalid Telnyx public key');
        }

        // Ed25519 public key is 32 bytes; sodium needs 32 bytes for verify
        $signatureBytes = base64_decode($signature, true);

        if ($signatureBytes === false) {
            throw new AdapterException('Invalid Telnyx webhook signature encoding');
        }

        $verified = sodium_crypto_sign_verify_detached(
            $signatureBytes,
            $payload,
            $publicKey,
        );

        if (! $verified) {
            throw new AdapterException('Invalid Telnyx webhook signature');
        }
    }
}
