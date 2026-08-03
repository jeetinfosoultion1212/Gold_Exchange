<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__) . '/helpers/desktop_print_helper.php';

if (!ge_is_desktop_app()) {
    echo json_encode(['ok' => false, 'error' => 'Silent print is only available in desktop mode.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
$html = is_array($data) ? (string) ($data['html'] ?? '') : '';

if ($html === '') {
    echo json_encode(['ok' => false, 'error' => 'No HTML content to print.']);
    exit;
}

echo json_encode(ge_silent_print_html_string($html));
