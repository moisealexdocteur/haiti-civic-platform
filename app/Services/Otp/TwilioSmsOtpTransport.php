<?php

namespace App\Services\Otp;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TwilioSmsOtpTransport implements OtpTransportInterface
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
        $this->httpPost = $httpPost ?? Closure::fromCallable(
            [$this, 'postForm']
        );
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::SMS;
    }

    public function deliver(
        OtpDeliveryRequest $request
    ): OtpDeliveryResult {
        $minutes = max(1, (int) ceil($request->ttlSeconds / 60));
        $message = sprintf(
            'Code de verification: %s. Expire dans %d minutes.',
            $request->code,
            $minutes
        );

        $fields = [
            'To' => $request->normalizedPhone,
            'Body' => $message,
        ];

        if ($this->messagingServiceSid !== null) {
            $fields['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $fields['From'] = (string) $this->fromNumber;
        }

        $url = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            $this->accountSid
        );

        try {
            $response = ($this->httpPost)(
                $url,
                $this->accountSid,
                $this->authToken,
                http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
            );
        } catch (Throwable $exception) {
            return OtpDeliveryResult::rejected(
                OtpChannel::SMS,
                'twilio_transport_error',
                $exception->getMessage()
            );
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            return OtpDeliveryResult::rejected(
                OtpChannel::SMS,
                $this->httpFailureCode('twilio', $status),
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
                OtpChannel::SMS,
                'twilio_invalid_response'
            );
        }

        $sid = $decoded['sid'] ?? null;

        if (
            ! is_string($sid)
            || preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D', $sid) !== 1
        ) {
            return OtpDeliveryResult::rejected(
                OtpChannel::SMS,
                'twilio_missing_message_id'
            );
        }

        return OtpDeliveryResult::accepted(
            OtpChannel::SMS,
            $sid
        );
    }

    private function validateConfiguration(): void
    {
        if (
            preg_match('/^AC[0-9a-fA-F]{32}$/D', $this->accountSid)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'Twilio account SID is invalid.'
            );
        }

        if (
            strlen($this->authToken) < 20
            || strlen($this->authToken) > 256
            || preg_match('/\s/', $this->authToken) === 1
        ) {
            throw new InvalidArgumentException(
                'Twilio auth token is invalid.'
            );
        }

        if (
            $this->messagingServiceSid !== null
            && preg_match(
                '/^MG[0-9a-fA-F]{32}$/D',
                $this->messagingServiceSid
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Twilio Messaging Service SID is invalid.'
            );
        }

        if (
            $this->fromNumber !== null
            && preg_match('/^\+[1-9][0-9]{7,14}$/D', $this->fromNumber)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'Twilio sender number is invalid.'
            );
        }

        if ($this->messagingServiceSid === null && $this->fromNumber === null) {
            throw new InvalidArgumentException(
                'Twilio sender configuration is missing.'
            );
        }

        if ($this->messagingServiceSid !== null && $this->fromNumber !== null) {
            throw new InvalidArgumentException(
                'Twilio sender configuration must use one sender type.'
            );
        }
    }

    /** @return array{status:int,body:string} */
    private function postForm(
        string $url,
        string $username,
        string $password,
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
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
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

        if (is_array($decoded)) {
            $parts = array_filter([
                isset($decoded['message']) ? (string) $decoded['message'] : null,
                isset($decoded['code']) ? 'Twilio ' . (string) $decoded['code'] : null,
            ]);

            if ($parts !== []) {
                return implode(' | ', $parts);
            }
        }

        return 'HTTP ' . $status;
    }
}
