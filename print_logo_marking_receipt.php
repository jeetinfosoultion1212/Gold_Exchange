<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');
session_start();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$company_name = $_SESSION['company_name'] ?? 'Gold Trading Company';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/logo_marking_helper.php';
require_once __DIR__ . '/helpers/desktop_print_helper.php';

$silentPrint = ge_wants_silent_print();

if (!function_exists('lm_format_purity_display')) {
    function lm_format_purity_display($purity): string
    {
        $options = function_exists('lm_purity_options')
            ? lm_purity_options()
            : ['22K', '20K', '18K', '14K', '9K', '999', '925', '875'];
        $s = trim((string) ($purity ?? ''));
        if ($s === '') {
            return '—';
        }
        if (in_array($s, $options, true)) {
            return $s;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $s)) {
            $n = (int) round((float) $s);
            $k = $n . 'K';
            if (in_array($k, $options, true)) {
                return $k;
            }
            $plain = (string) $n;
            if (in_array($plain, $options, true)) {
                return $plain;
            }
        }
        return $s;
    }
}

$request_id = (int) ($_GET['id'] ?? 0);
if ($request_id <= 0) {
    die('No request ID provided');
}

$header = lm_fetch_logo_marking_header($conn, $request_id, $company_id);
if (!$header) {
    die('Request not found');
}

$request = $header;
$items = lm_fetch_request_items($conn, $request_id, $company_id);

$created_dt = new DateTime($request['request_date'], new DateTimeZone('Asia/Kolkata'));
$date = $created_dt->format('d/m/Y');
$time = $created_dt->format('h:i A');

$h = static function ($str) {
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
};

function lm_render_receipt_copy(
    callable $h,
    string $company_name,
    array $request,
    array $items,
    string $copy_label,
    string $date,
    string $time
): string {
    $total_pcs = 0;
    $total_wt = 0.0;
    foreach ($items as $it) {
        $total_pcs += (int) ($it['pieces'] ?? 0);
        $total_wt += (float) ($it['weight'] ?? 0);
    }

    ob_start();
    ?>
    <div class="receipt-copy">
        <div class="copy-badge"><?= $h($copy_label) ?></div>
        <div class="center company-name"><?= $h($company_name) ?></div>
        <div class="center receipt-title">LOGO MARKING RECEIPT</div>
        <div class="divider"></div>

        <div class="row receipt-date-row">
            <span class="receipt-date-left"><span class="label">Receipt:</span> <?= $h($request['receipt_id']) ?></span>
            <span class="receipt-date-right"><?= $h($date . ' ' . $time) ?></span>
        </div>
        <div class="row"><span class="label">Jeweller:</span><span class="value"><?= $h($request['jeweller_name']) ?></span></div>
        <?php if (!empty($request['mobile'])): ?>
        <div class="row"><span class="label">Mobile:</span><span class="value"><?= $h($request['mobile']) ?></span></div>
        <?php endif; ?>

        <div class="logo-box-row">
            <div class="logo-box-cell">
                <span class="lb-label">LOGO</span>
                <span class="lb-value"><?= $h($request['logo'] ?: '—') ?></span>
            </div>
            <div class="logo-box-cell">
                <span class="lb-label">BOX NO</span>
                <span class="lb-value"><?= $h($request['box_no'] ?: '—') ?></span>
            </div>
        </div>
        <div class="divider"></div>

        <div class="items-title">Items</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th class="col-item">Item</th>
                    <th class="col-num col-em-h">Pcs</th>
                    <th class="col-wt col-em-h">Wt(g)</th>
                    <th class="col-pur col-em-h">Purity</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 1; foreach ($items as $it): ?>
                <tr>
                    <td><?= $n++ ?></td>
                    <td class="col-item"><?= $h($it['item_name']) ?></td>
                    <td class="col-num col-em"><strong><?= (int) $it['pieces'] ?></strong></td>
                    <td class="col-wt col-em"><strong><?= number_format((float) ($it['weight'] ?? 0), 3) ?></strong></td>
                    <td class="col-pur col-em"><strong><?= $h(lm_format_purity_display($it['purity'] ?? '')) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="items-total-row">
                    <td colspan="2" class="col-item total-label">Total</td>
                    <td class="col-num col-em"><strong><?= $total_pcs ?></strong></td>
                    <td class="col-wt col-em"><strong><?= number_format($total_wt, 3) ?></strong></td>
                    <td class="col-pur"></td>
                </tr>
            </tfoot>
        </table>

        <div class="footer">Thank you!</div>
    </div>
    <?php
    return (string) ob_get_clean();
}

if ($silentPrint) {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt <?= $h($request['receipt_id']) ?></title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Courier New', Courier, monospace; font-size: 11px; color: #000; }
        .print-stack { display: block; }
        .receipt-copy { width: 72mm; padding: 4mm; page-break-after: always; }
        .receipt-copy:last-child { page-break-after: auto; }
        .copy-badge { text-align: center; font-size: 12px; font-weight: 900; border: 2px solid #000; padding: 2px 4px; margin-bottom: 6px; }
        .center { text-align: center; }
        .company-name { font-size: 16px; font-weight: 900; }
        .divider { border-bottom: 1.5px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; margin: 3px 0; font-size: 10px; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 9px; }
        .items-table th, .items-table td { padding: 2px 1px; text-align: right; }
        .items-table th:first-child, .items-table td:first-child { text-align: center; }
        .footer { text-align: center; border-top: 1.5px dashed #000; padding-top: 6px; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="print-stack">
        <?= lm_render_receipt_copy($h, $company_name, $request, $items, 'OFFICE COPY', $date, $time) ?>
        <?= lm_render_receipt_copy($h, $company_name, $request, $items, 'DUPLICATE COPY', $date, $time) ?>
    </div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    ge_finish_silent_print(ge_silent_print_html_string($html));
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= $h($request['receipt_id']) ?></title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-weight: normal;
            background: #525659;
            min-height: 100vh;
            padding: 12px;
        }
        .print-stack {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        .receipt-copy {
            background: #fff;
            width: 72mm;
            max-width: 100%;
            padding: 5mm 4mm;
            color: #000;
            font-size: 11px;
            font-weight: normal;
            line-height: 1.35;
            page-break-after: always;
            break-after: page;
        }
        .receipt-copy:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .copy-badge {
            text-align: center;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.12em;
            border: 2px solid #000;
            padding: 2px 4px;
            margin-bottom: 6px;
        }
        .center { text-align: center; }
        .company-name { font-size: 16px; font-weight: 900; margin-bottom: 2px; }
        .receipt-title { font-size: 11px; margin-bottom: 4px; }
        .divider { border-bottom: 1.5px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; margin: 3px 0; font-size: 10px; }
        .receipt-date-row {
            font-size: 10px;
            font-weight: 700;
            align-items: baseline;
        }
        .receipt-date-left { flex: 1; min-width: 0; }
        .receipt-date-right { flex: 0 0 auto; text-align: right; white-space: nowrap; font-size: 9px; }
        .label { flex: 0 0 auto; }
        .value { text-align: right; flex: 1; word-break: break-word; }
        .logo-box-row {
            display: flex;
            gap: 6px;
            margin: 6px 0 2px;
        }
        .logo-box-cell {
            flex: 1;
            border: 2px solid #000;
            padding: 4px 3px;
            text-align: center;
        }
        .lb-label {
            display: block;
            font-size: 8px;
            letter-spacing: 0.08em;
            margin-bottom: 2px;
        }
        .lb-value {
            display: block;
            font-size: 18px;
            font-weight: 900;
            line-height: 1.1;
            word-break: break-word;
        }
        .items-title { font-size: 10px; margin-bottom: 3px; }
        .items-table { width: 100%; border-collapse: collapse; margin: 2px 0 4px; }
        .items-table th,
        .items-table td {
            padding: 3px 2px;
            font-size: 9px;
            font-weight: normal;
            text-align: right;
        }
        .items-table th:first-child,
        .items-table td:first-child { text-align: center; width: 8%; }
        .items-table th { border-bottom: 1.5px dashed #000; font-size: 9px; font-weight: 700; }
        .items-table .col-item { text-align: left; font-size: 9px; font-weight: normal; width: 34%; }
        .items-table th.col-num,
        .items-table th.col-wt,
        .items-table th.col-pur,
        .items-table th.col-em-h {
            font-size: 9px !important;
            font-weight: 700 !important;
        }
        .items-table td.col-em {
            font-size: 9px !important;
            font-weight: 700 !important;
            line-height: 1.2;
        }
        .items-table td.col-em strong {
            font-size: inherit !important;
            font-weight: 700 !important;
        }
        .items-table tfoot .items-total-row td.col-em {
            font-size: 9px !important;
            font-weight: 700 !important;
        }
        .items-table tfoot .items-total-row td.col-em strong {
            font-size: inherit !important;
            font-weight: 700 !important;
        }
        .items-table tfoot .items-total-row td {
            border-top: 1.5px dashed #000;
            padding-top: 4px;
        }
        .items-table tfoot .total-label {
            text-align: right;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
        }
        .footer { text-align: center; border-top: 1.5px dashed #000; padding-top: 6px; margin-top: 8px; font-size: 9px; }
        @media print {
            body { background: #fff; padding: 0; }
            .print-stack { gap: 0; }
            .receipt-copy { margin: 0 auto; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="print-stack">
        <?= lm_render_receipt_copy($h, $company_name, $request, $items, 'OFFICE COPY', $date, $time) ?>
        <?= lm_render_receipt_copy($h, $company_name, $request, $items, 'DUPLICATE COPY', $date, $time) ?>
    </div>
    <script>
        window.addEventListener('load', function () {
        if (!window.GE_ELECTRON_APP) {
            setTimeout(function () { window.print(); }, 350);
        }
        });
    </script>
</body>
</html>
