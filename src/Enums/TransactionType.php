<?php

namespace XLaravel\Payline\Enums;

enum TransactionType: string
{
    case Payment = 'payment';
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Refund = 'refund';
    case Void = 'void';
}
