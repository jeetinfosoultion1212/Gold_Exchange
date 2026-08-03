<?php

/** Map UI / legacy labels to transactions.payment_method enum values. */
function ge_normalize_payment_method(?string $method): string
{
    $method = trim((string) $method);
    $aliases = [
        '' => 'Cash',
        'Bank Transfer' => 'Bank',
        'NEFT' => 'Bank',
        'RTGS' => 'Bank',
        'Other' => 'Bank',
    ];
    if (isset($aliases[$method])) {
        return $aliases[$method];
    }

    $allowed = ['Cash', 'Bank', 'UPI', 'Cheque'];
    return in_array($method, $allowed, true) ? $method : 'Cash';
}

/** Never persist an invalid/empty payment_status (causes MySQL enum truncation). */
function ge_normalize_payment_status(?string $status, float $amount, float $payment_amount): string
{
    $status = trim((string) $status);
    $allowed = ['Paid', 'Partial', 'Due', 'Pending'];
    if (in_array($status, $allowed, true)) {
        return $status;
    }

    if ($amount > 0 && $payment_amount >= $amount) {
        return 'Paid';
    }
    if ($payment_amount > 0) {
        return 'Partial';
    }
    return 'Due';
}
