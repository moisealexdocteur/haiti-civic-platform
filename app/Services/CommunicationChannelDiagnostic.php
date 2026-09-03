<?php

namespace App\Services;

final class CommunicationChannelDiagnostic
{
    public function failure(
        string $channel,
        string $code,
        ?string $providerDetail = null
    ): array {
        $adviceKey = match (true) {
            $code === 'smtp_gmail_host_invalid' => 'Admin.channelAdviceGmailHost',
            $code === 'smtp_send_failed' => 'Admin.channelAdviceSmtpRejected',
            $code === 'smtp_transport_error' => 'Admin.channelAdviceNetwork',
            str_starts_with($code, 'twilio_http_4') => 'Admin.channelAdviceCredentials',
            str_starts_with($code, 'meta_http_4') => 'Admin.channelAdviceCredentials',
            str_contains($code, 'transport_error') => 'Admin.channelAdviceNetwork',
            default => 'Admin.channelAdviceReview',
        };

        return [
            'ok' => false,
            'channel' => $channel,
            'title' => lang('Admin.channelTestFailed'),
            'message' => lang('Admin.channelTestFailedLead'),
            'provider_detail' => $providerDetail,
            'advice' => lang($adviceKey),
            'failure_code' => $code,
        ];
    }

    public function success(string $channel): array
    {
        return [
            'ok' => true,
            'channel' => $channel,
            'title' => lang('Admin.channelTestSucceeded'),
            'message' => lang('Admin.channelTestSucceededLead'),
            'provider_detail' => null,
            'advice' => null,
            'failure_code' => null,
        ];
    }
}
