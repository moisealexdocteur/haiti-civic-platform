<?php

namespace App\Services;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TwilioSmsMessageSender
{
    private Closure $httpPost;

    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly ?string $fromNumber = null,
        private readonly ?string $messagingServiceSid = null,
        ?Closure $httpPost = null
    ) {
        $this->validateConfiguration();
        $this->httpPost = $httpPost ?? Closure::fromCallable([$this, 'postForm']);
    }

    /** @return array{accepted:bool,messageId:?string,failureCode:?string,providerDetail:?string} */
    public function send(string $phone, string $message): array
    {
        if (preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Recipient phone number is invalid.');
        }

        $message = trim($message);

        if ($message === '' || mb_strlen($message) > 600) {
            throw new InvalidArgumentException('SMS message length is invalid.');
        }

        $fields = ['To' => $phone, 'Body' => $message];

        if ($this->messagingServiceSid !== null) {
            $fields['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $fields['From'] = (string) $this->fromNumber;
        }

        try {
            $response = ($this->httpPost)(
                'https://api.twilio.com/2010-04-01/Accounts/' . $this->accountSid . '/Messages.json',
                $this->accountSid,
                $this->authToken,
                http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
            );
        } catch (Throwable $exception) {
            return $this->failed('twilio_transport_error', $exception->getMessage());
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            return $this->failed(
                $status >= 400 && $status <= 599
                    ? 'twilio_http_' . intdiv($status, 100) . 'xx'
                    : 'twilio_http_error',
                $this->providerError($body, $status)
            );
        }

        $decoded = json_decode($body, true);
        $sid = is_array($decoded) ? ($decoded['sid'] ?? null) : null;

        if (! is_string($sid) || preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D', $sid) !== 1) {
            return $this->failed('twilio_missing_message_id', null);
        }

        return [
            'accepted' => true,
            'messageId' => $sid,
            'failureCode' => null,
            'providerDetail' => null,
        ];
    }

    private function validateConfiguration(): void
    {
        if (preg_match('/^AC[0-9a-fA-F]{32}$/D', $this->accountSid) !== 1) {
            throw new InvalidArgumentException('Twilio account SID is invalid.');
        }

        if (strlen($this->authToken) < 20 || strlen($this->authToken) > 256 || preg_match('/\s/', $this->authToken) === 1) {
            throw new InvalidArgumentException('Twilio auth token is invalid.');
        }

        if ($this->fromNumber !== null && preg_match('/^\+[1-9][0-9]{7,14}$/D', $this->fromNumber) !== 1) {
            throw new InvalidArgumentException('Twilio sender number is invalid.');
        }

        if ($this->messagingServiceSid !== null && preg_match('/^MG[0-9a-fA-F]{32}$/D', $this->messagingServiceSid) !== 1) {
            throw new InvalidArgumentException('Twilio Messaging Service SID is invalid.');
        }

        if (($this->fromNumber === null) === ($this->messagingServiceSid === null)) {
            throw new InvalidArgumentException('Twilio requires exactly one sender type.');
        }
    }

    /** @return array{accepted:bool,messageId:?string,failureCode:string,providerDetail:?string} */
    private function failed(string $code, ?string $detail): array
    {
        return [
            'accepted' => false,
            'messageId' => null,
            'failureCode' => $code,
            'providerDetail' => $detail === null ? null : mb_substr($detail, 0, 500),
        ];
    }

    private function providerError(string $body, int $status): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['message'])) {
            return mb_substr((string) $decoded['message'], 0, 500);
        }

        return 'HTTP ' . $status;
    }

    /** @return array{status:int,body:string} */
    private function postForm(string $url, string $username, string $password, string $body): array
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
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_USERPWD => $username . ':' . $password,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
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
                throw new RuntimeException('Twilio HTTPS request failed.');
            }

            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $responseBody];
        } finally {
            curl_close($handle);
        }
    }
}
