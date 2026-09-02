<?php

namespace App\Services\Otp;

enum OtpChannel: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
}
