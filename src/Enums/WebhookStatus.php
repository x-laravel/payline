<?php

namespace XLaravel\Payline\Enums;

enum WebhookStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
