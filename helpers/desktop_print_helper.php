<?php
/**
 * Silent printing for desktop / offline mode (Windows default printer, no dialog).
 * Uses bundled SumatraPDF: -print-to-default -silent
 */

function ge_is_desktop_app(): bool
{
    return getenv('PHPDESKTOP_VERSION') !== false
        || getenv('GOLD_EXCHANGE_DESKTOP') !== false;
}

/** Desktop mode: print silently unless ?preview=1 (for debugging). */
function ge_wants_silent_print(): bool
{
    if (!ge_is_desktop_app()) {
        return false;
    }
    return !isset($_GET['preview']) || (string) $_GET['preview'] !== '1';
}

function ge_print_is_ajax(): bool
{
    if (!empty($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
        return true;
    }
    $with = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strtolower((string) $with) === 'xmlhttprequest';
}

function ge_sumatra_path(): ?string
{
    $candidates = array_filter([
        getenv('GOLD_EXCHANGE_SUMATRA') ?: null,
        dirname(__DIR__) . '/runtime/SumatraPDF/SumatraPDF.exe',
        dirname(__DIR__) . '/../runtime/SumatraPDF/SumatraPDF.exe',
    ]);

    foreach ($candidates as $path) {
        if ($path && is_file($path)) {
            return $path;
        }
    }

    return null;
}

function ge_silent_print_file(string $filePath): array
{
    if (!is_file($filePath)) {
        return ['ok' => false, 'error' => 'Print file not found.'];
    }

    $sumatra = ge_sumatra_path();
    if (!$sumatra) {
        return [
            'ok' => false,
            'error' => 'SumatraPDF not found. Rebuild the desktop package (CREATE_PORTABLE_APP.bat).',
        ];
    }

    $cmd = escapeshellarg($sumatra)
        . ' -print-to-default -silent -exit-when-done '
        . escapeshellarg($filePath);

    exec($cmd, $output, $code);

    if ($code !== 0) {
        return ['ok' => false, 'error' => 'Printer command failed (code ' . $code . ').'];
    }

    return ['ok' => true];
}

function ge_silent_print_binary(string $contents, string $extension): array
{
    $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'pdf';
    $tmp = tempnam(sys_get_temp_dir(), 'ge_print_');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Could not create temp file.'];
    }

    $path = $tmp . '.' . $extension;
    rename($tmp, $path);
    file_put_contents($path, $contents);
    $result = ge_silent_print_file($path);
    @unlink($path);

    return $result;
}

function ge_silent_print_pdf_string(string $pdfBinary): array
{
    return ge_silent_print_binary($pdfBinary, 'pdf');
}

function ge_silent_print_html_string(string $html): array
{
    return ge_silent_print_binary($html, 'html');
}

function ge_finish_silent_print(array $result): void
{
    if (ge_print_is_ajax()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result);
        exit;
    }

    if (!empty($result['ok'])) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Printing</title></head><body>'
            . '<p style="font-family:sans-serif;padding:16px;">Sent to default printer&hellip;</p>'
            . '<script>setTimeout(function(){window.close();},600);</script></body></html>';
        exit;
    }

    $msg = htmlspecialchars((string) ($result['error'] ?? 'Print failed'), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><body><p style="color:#b91c1c;font-family:sans-serif;padding:16px;">'
        . $msg . '</p></body></html>';
    exit;
}
