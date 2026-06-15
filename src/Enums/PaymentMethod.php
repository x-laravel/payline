<?php

namespace XLaravel\Payline\Enums;

enum PaymentMethod: string
{
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case BankTransfer = 'bank_transfer';
    case Token = 'token';
}
