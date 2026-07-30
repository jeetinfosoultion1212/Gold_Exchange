<?php

/**
 * Receipt / booking / purchase ID format: {company_id}{code}{serial}
 * e.g. 12EX1, 17B2, 12S001, 12P001
 *
 * Also recognizes legacy {code}{company_id}{serial} (B17, S12) and bare EX{n} for exchange.
 */

function receipt_id_new_prefix(int $company_id, string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Z]/', '', $code));
    return (string) $company_id . $code;
}

function receipt_id_legacy_prefix(int $company_id, string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Z]/', '', $code));
    return $code . (string) $company_id;
}

function receipt_id_extract_serial(string $receipt_id, int $company_id, string $code): ?int
{
    $code = strtoupper(preg_replace('/[^A-Z]/', '', $code));
    $newPrefix = receipt_id_new_prefix($company_id, $code);
    $legacyPrefix = receipt_id_legacy_prefix($company_id, $code);

    if (str_starts_with($receipt_id, $newPrefix)) {
        $tail = substr($receipt_id, strlen($newPrefix));
        return ($tail !== '' && ctype_digit($tail)) ? (int) $tail : null;
    }

    if (str_starts_with($receipt_id, $legacyPrefix)) {
        $tail = substr($receipt_id, strlen($legacyPrefix));
        return ($tail !== '' && ctype_digit($tail)) ? (int) $tail : null;
    }

    // Legacy exchange IDs without company prefix (EX1, EX2, …)
    if ($code === 'EX' && preg_match('/^EX(\d+)$/i', $receipt_id, $matches)) {
        return (int) $matches[1];
    }

    return null;
}

/**
 * @param array{pad_length?: int, transaction_type?: string} $options
 */
function next_receipt_id(mysqli $conn, int $company_id, string $code, array $options = []): string
{
    $padLength = (int) ($options['pad_length'] ?? 0);
    $transactionType = $options['transaction_type'] ?? null;
    $newPrefix = receipt_id_new_prefix($company_id, $code);

    $sql = 'SELECT receipt_id FROM transactions WHERE company_id = ?';
    $types = 'i';
    $params = [$company_id];

    if ($transactionType !== null && $transactionType !== '') {
        $sql .= ' AND transaction_type = ?';
        $types .= 's';
        $params[] = $transactionType;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare receipt ID query: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $maxSerial = 0;
    while ($row = $result->fetch_assoc()) {
        $serial = receipt_id_extract_serial((string) $row['receipt_id'], $company_id, $code);
        if ($serial !== null && $serial > $maxSerial) {
            $maxSerial = $serial;
        }
    }

    $nextSerial = $maxSerial + 1;
    $serialStr = $padLength > 0
        ? str_pad((string) $nextSerial, $padLength, '0', STR_PAD_LEFT)
        : (string) $nextSerial;

    return $newPrefix . $serialStr;
}

function receipt_id_exists_globally(mysqli $conn, string $receipt_id): bool
{
    $stmt = $conn->prepare('SELECT id FROM transactions WHERE receipt_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare duplicate receipt check: ' . $conn->error);
    }

    $stmt->bind_param('s', $receipt_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Return $proposed_id if globally unique; otherwise generate the next ID for this company/code.
 *
 * @param array{pad_length?: int, transaction_type?: string} $options
 */
function ensure_unique_receipt_id(mysqli $conn, int $company_id, string $code, string $proposed_id, array $options = []): string
{
    $proposed_id = trim($proposed_id);
    if ($proposed_id !== '' && !receipt_id_exists_globally($conn, $proposed_id)) {
        return $proposed_id;
    }

    return next_receipt_id($conn, $company_id, $code, $options);
}
