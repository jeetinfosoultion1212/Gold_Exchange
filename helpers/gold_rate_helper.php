<?php
/**
 * Gold rate unit setting: per gram (stored in DB) vs per 10g (display/input).
 * Rates in transactions are always stored as ₹/gram.
 */

const GOLD_RATE_SETTING_KEY = 'gold_rate_unit';
const GOLD_RATE_UNIT_GRAM = 'gram';
const GOLD_RATE_UNIT_10GRAM = '10gram';

function gold_rate_get_unit(mysqli $conn, int $company_id): string
{
    $key = GOLD_RATE_SETTING_KEY;
    $cid = (int) $company_id;
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE company_id = $cid AND setting_key = '$key' LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $v = trim((string) $row['setting_value']);
        if ($v === GOLD_RATE_UNIT_10GRAM) {
            return GOLD_RATE_UNIT_10GRAM;
        }
    }
    return GOLD_RATE_UNIT_GRAM;
}

function gold_rate_save_unit(mysqli $conn, int $company_id, string $unit): bool
{
    $unit = ($unit === GOLD_RATE_UNIT_10GRAM) ? GOLD_RATE_UNIT_10GRAM : GOLD_RATE_UNIT_GRAM;
    $key = $conn->real_escape_string(GOLD_RATE_SETTING_KEY);
    $val = $conn->real_escape_string($unit);
    $cid = (int) $company_id;
    $chk = $conn->query("SELECT id FROM system_settings WHERE company_id = $cid AND setting_key = '$key' LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        return (bool) $conn->query("UPDATE system_settings SET setting_value = '$val', updated_at = NOW() WHERE company_id = $cid AND setting_key = '$key'");
    }
    return (bool) $conn->query(
        "INSERT INTO system_settings (company_id, setting_key, setting_value, setting_type, description)
         VALUES ($cid, '$key', '$val', 'string', 'Gold rate display unit: gram or 10gram')"
    );
}

function gold_rate_divisor(string $unit): float
{
    return ($unit === GOLD_RATE_UNIT_10GRAM) ? 10.0 : 1.0;
}

function gold_rate_label(string $unit): string
{
    return ($unit === GOLD_RATE_UNIT_10GRAM) ? '₹/10g' : '₹/g';
}

function gold_rate_suffix(string $unit): string
{
    return ($unit === GOLD_RATE_UNIT_10GRAM) ? '/10g' : '/g';
}

function gold_rate_to_display(float $perGramRate, string $unit): float
{
    return round($perGramRate * gold_rate_divisor($unit), 2);
}

function gold_rate_from_display(float $displayRate, string $unit): float
{
    $d = gold_rate_divisor($unit);
    return $d > 0 ? round($displayRate / $d, 2) : $displayRate;
}

/** @return array{unit:string,divisor:float,label:string,suffix:string} */
function gold_rate_js_config(string $unit): array
{
    return [
        'unit' => $unit,
        'divisor' => gold_rate_divisor($unit),
        'label' => gold_rate_label($unit),
        'suffix' => gold_rate_suffix($unit),
    ];
}

function gold_rate_apply_display_to_row(array &$row, string $unit, string $field = 'rate'): void
{
    if (array_key_exists($field, $row)) {
        $row[$field] = gold_rate_to_display(floatval($row[$field]), $unit);
    }
}
