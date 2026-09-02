<?php

namespace App\Services\Otp;

use InvalidArgumentException;
use RuntimeException;

final class OtpChannelRouter
{
    /** @var array<string, OtpTransportInterface> */
    private array $transports = [];

    /**
     * @param iterable<OtpTransportInterface> $transports
     */
    public function __construct(iterable $transports)
    {
        foreach ($transports as $transport) {
            if (! $transport instanceof OtpTransportInterface) {
                throw new InvalidArgumentException(
                    'Every OTP transport must implement OtpTransportInterface.'
                );
            }

            $key = $transport->channel()->value;

            if (isset($this->transports[$key])) {
                throw new InvalidArgumentException(
                    'Duplicate OTP transport for channel: ' . $key
                );
            }

            $this->transports[$key] = $transport;
        }
    }

    /**
     * @param list<OtpChannel>|null $preference
     */
    public function deliver(
        OtpDeliveryRequest $request,
        ?array $preference = null
    ): OtpDeliveryResult {
        $preference ??= [
            OtpChannel::WHATSAPP,
            OtpChannel::SMS,
        ];

        if ($preference === []) {
            throw new InvalidArgumentException(
                'At least one OTP channel must be preferred.'
            );
        }

        $lastFailure = null;

        foreach ($preference as $channel) {
            if (! $channel instanceof OtpChannel) {
                throw new InvalidArgumentException(
                    'OTP preference contains an invalid channel.'
                );
            }

            $transport = $this->transports[$channel->value] ?? null;

            if ($transport === null) {
                continue;
            }

            $result = $transport->deliver($request);

            if ($result->channel !== $channel) {
                throw new RuntimeException(
                    'OTP transport returned a result for another channel.'
                );
            }

            if ($result->accepted) {
                return $result;
            }

            $lastFailure = $result;
        }

        if ($lastFailure !== null) {
            return $lastFailure;
        }

        throw new RuntimeException(
            'No configured OTP transport matches the requested channels.'
        );
    }
}
