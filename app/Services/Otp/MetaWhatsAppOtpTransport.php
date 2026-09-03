<?php

namespace App\Services\Otp;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MetaWhatsAppOtpTransport implements OtpTransportInterface
{
    private Closure $httpPost;

    public function __construct(
        private readonly string $graphVersion,
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
        private readonly string $templateName,
        private readonly string $templateLanguage,
        ?Closure $httpPost = null
    ) {
        $this->validateConfiguration();
        $this->httpPost = $httpPost ?? Closure::fromCallable(
            [$this, 'postJson']
        );
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::WHATSAPP;
    }

    public function deliver(
        OtpDeliveryRequest $request
    ): OtpDeliveryResult {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->graphVersion,
            $this->phoneNumberId
        );

        $recipient = ltrim($request->normalizedPhone, '+');

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $this->templateName,
                'language' => [
                    'code' => $this->templateLanguage,
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $request->code,
                            ],
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $request->code,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = ($this->httpPost)(
                $url,
                [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (Throwable $exception) {
            return OtpDeliveryResult::rejected(
                OtpChannel::WHATSAPP,
                'meta_transport_error',
                $exception->getMessage()
            );
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            return OtpDeliveryResult::rejected(
                OtpChannel::WHATSAPP,
                $this->httpFailureCode('meta', $status),
                $this->providerError($body, $status)
            );
        }

        try {
            $decoded = json_decode(
                $body,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            return OtpDeliveryResult::rejected(
                OtpChannel::WHATSAPP,
                'meta_invalid_response'
            );
        }

        $messageId = $decoded['messages'][0]['id'] ?? null;

        if (! is_string($messageId) || trim($messageId) === '') {
            return OtpDeliveryResult::rejected(
                OtpChannel::WHATSAPP,
                'meta_missing_message_id'
            );
        }

        return OtpDeliveryResult::accepted(
            OtpChannel::WHATSAPP,
            mb_substr(trim($messageId), 0, 191)
        );
    }

    private function validateConfiguration(): void
    {
        if (
            preg_match('/^v[0-9]{1,2}\.[0-9]{1,2}$/D', $this->graphVersion)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'WhatsApp Graph API version is invalid.'
            );
        }

        if (preg_match('/^[0-9]{5,30}$/D', $this->phoneNumberId) !== 1) {
            throw new InvalidArgumentException(
                'WhatsApp phone number ID is invalid.'
            );
        }

        if (
            strlen($this->accessToken) < 20
            || strlen($this->accessToken) > 4096
            || preg_match('/\s/', $this->accessToken) === 1
        ) {
            throw new InvalidArgumentException(
                'WhatsApp access token is invalid.'
            );
        }

        if (
            preg_match('/^[a-z0-9_]{1,512}$/D', $this->templateName)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'WhatsApp OTP template name is invalid.'
            );
        }

        if (
            preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?$/D', $this->templateLanguage)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'WhatsApp OTP template language is invalid.'
            );
        }
    }

    /** @return array{status:int,body:string} */
    private function postJson(
        string $url,
        array $headers,
        string $body
    ): array {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is unavailable.');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialize HTTPS client.');
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);

            $responseBody = curl_exec($handle);

            if (! is_string($responseBody)) {
                throw new RuntimeException('WhatsApp HTTPS request failed.');
            }

            return [
                'status' => (int) curl_getinfo(
                    $handle,
                    CURLINFO_RESPONSE_CODE
                ),
                'body' => $responseBody,
            ];
        } finally {
            curl_close($handle);
        }
    }

    private function httpFailureCode(string $prefix, int $status): string
    {
        if ($status >= 400 && $status <= 599) {
            return $prefix . '_http_' . intdiv($status, 100) . 'xx';
        }

        return $prefix . '_http_error';
    }

    private function providerError(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (is_array($error)) {
            $parts = array_filter([
                isset($error['message']) ? (string) $error['message'] : null,
                isset($error['code']) ? 'Meta ' . (string) $error['code'] : null,
                isset($error['error_subcode']) ? 'sous-code ' . (string) $error['error_subcode'] : null,
            ]);

            if ($parts !== []) {
                return implode(' | ', $parts);
            }
        }

        return 'HTTP ' . $status;
    }
}
