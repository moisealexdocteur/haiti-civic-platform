<?php

namespace App\Services\Otp;

interface OtpTransportInterface
{
    public function channel(): OtpChannel;

    public function deliver(
        OtpDeliveryRequest $request
    ): OtpDeliveryResult;
}
