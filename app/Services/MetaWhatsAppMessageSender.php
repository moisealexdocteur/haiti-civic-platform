<?php

namespace App\Services;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MetaWhatsAppMessageSender
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
        $this->validate();
        $this->httpPost = $httpPost ?? Closure::fromCallable([$this, 'postJson']);
    }

    /** @return array{accepted:bool,messageId:?string,failureCode:?string,providerDetail:?string} */
    public function send(string $phone, string $message): array
    {
        if (preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('WhatsApp recipient is invalid.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($phone, '+'),
            'type' => 'template',
            'template' => [
                'name' => $this->templateName,
                'language' => ['code' => $this->templateLanguage],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [[
                        'type' => 'text',
                        'text' => mb_substr(trim($message), 0, 1000),
                    ]],
                ]],
            ],
        ];

        try {
            $response = ($this->httpPost)(
                sprintf('https://graph.facebook.com/%s/%s/messages', $this->graphVersion, $this->phoneNumberId),
                ['Authorization: Bearer ' . $this->accessToken, 'Content-Type: application/json', 'Accept: application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable $exception) {
            return $this->failed('meta_transport_error', $exception->getMessage());
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            return $this->failed(
                $status >= 400 && $status <= 599 ? 'meta_http_' . intdiv($status, 100) . 'xx' : 'meta_http_error',
                $this->providerError($body, $status)
            );
        }

        $decoded = json_decode($body, true);
        $messageId = is_array($decoded) ? ($decoded['messages'][0]['id'] ?? null) : null;

        if (! is_string($messageId) || trim($messageId) === '') {
            return $this->failed('meta_missing_message_id', null);
        }

        return ['accepted' => true, 'messageId' => mb_substr(trim($messageId), 0, 191), 'failureCode' => null, 'providerDetail' => null];
    }

    private function validate(): void
    {
        if (preg_match('/^v[0-9]{1,2}\.[0-9]{1,2}$/D', $this->graphVersion) !== 1
            || preg_match('/^[0-9]{5,30}$/D', $this->phoneNumberId) !== 1
            || strlen($this->accessToken) < 20
            || preg_match('/^[a-z0-9_]{1,512}$/D', $this->templateName) !== 1
            || preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?$/D', $this->templateLanguage) !== 1) {
            throw new InvalidArgumentException('WhatsApp notification configuration is invalid.');
        }
    }

    private function providerError(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) && isset($decoded['error']['message'])
            ? (string) $decoded['error']['message']
            : 'HTTP ' . $status;
    }

    private function failed(string $code, ?string $detail): array
    {
        return ['accepted' => false, 'messageId' => null, 'failureCode' => $code, 'providerDetail' => NotificationDeliveryService::sanitizeProviderDetail($detail)];
    }

    /** @return array{status:int,body:string} */
    private function postJson(string $url, array $headers, string $body): array
    {
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
            $response = curl_exec($handle);
            if (! is_string($response)) {
                throw new RuntimeException('WhatsApp HTTPS request failed.');
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response];
        } finally {
            curl_close($handle);
        }
    }
}
