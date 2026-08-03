<?php
date_default_timezone_set('Asia/Kolkata');
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/transaction_helper.php';
require_once __DIR__ . '/helpers/logo_marking_helper.php';
require_once __DIR__ . '/handlers/account_balance_helper.php';

if ($conn->connect_error) {
    die('Database connection failed.');
}

$company_id = (int) $_SESSION['company_id'];
$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_POST['action']) {
        case 'search_parties':
            $term = $conn->real_escape_string($_POST['term'] ?? '');
            $sql = "SELECT id, party_name, contact_no, address, cash_balance, logo
                    FROM parties
                    WHERE company_id = $company_id AND UPPER(party_name) LIKE UPPER('%$term%')
                    ORDER BY party_name ASC
                    LIMIT 10";
            $result = @$conn->query($sql);
            $parties = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $parties[] = [
                        'id' => (int) $row['id'],
                        'party_name' => $row['party_name'],
                        'contact_no' => $row['contact_no'] ?? '',
                        'address' => $row['address'] ?? '',
                        'logo' => $row['logo'] ?? '',
                        'cash_balance' => (float) ($row['cash_balance'] ?? 0),
                    ];
                }
            }
            echo json_encode($parties);
            exit;

        case 'save_party':
            $party_name = lm_normalize_party_name($_POST['party_name'] ?? '');
            if ($party_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'Party name required']);
                exit;
            }
            $address = trim($_POST['address'] ?? '');
            $contact_no = trim($_POST['contact_no'] ?? '');
            $logo = trim($_POST['logo'] ?? '');
            $gstin = trim($_POST['gstin'] ?? '') ?: 'N/A';
            $state = trim($_POST['state'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $bank_name = trim($_POST['bank_name'] ?? '');
            $account_no = trim($_POST['account_no'] ?? '');
            $ifsc_code = trim($_POST['ifsc_code'] ?? '');
            $cash_balance = floatval($_POST['cash_balance'] ?? 0);
            $bank_balance = floatval($_POST['bank_balance'] ?? 0);
            $gold_balance = floatval($_POST['gold_balance'] ?? $_POST['cash_gold_balance'] ?? 0);
            $silver_balance = floatval($_POST['silver_balance'] ?? $_POST['cash_silver_balance'] ?? 0);

            $category_rates_raw = $_POST['category_rates'] ?? '[]';
            if (is_string($category_rates_raw)) {
                $category_rates = json_decode($category_rates_raw, true);
            } else {
                $category_rates = $category_rates_raw;
            }
            if (!is_array($category_rates)) {
                $category_rates = [];
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    'INSERT INTO parties (company_id, party_name, address, contact_no, logo, gstin, state, city, bank_name, account_no, ifsc_code, cash_balance, bank_balance, gold_balance, silver_balance)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'issssssssssdddd',
                    $company_id,
                    $party_name,
                    $address,
                    $contact_no,
                    $logo,
                    $gstin,
                    $state,
                    $city,
                    $bank_name,
                    $account_no,
                    $ifsc_code,
                    $cash_balance,
                    $bank_balance,
                    $gold_balance,
                    $silver_balance
                );
                if (!$stmt->execute()) {
                    throw new Exception($conn->error ?: 'Could not save party');
                }
                $party_id = (int) $stmt->insert_id;
                $stmt->close();

                foreach ($category_rates as $cr) {
                    $cid = (int) ($cr['category_id'] ?? 0);
                    $cname = trim($cr['category_name'] ?? '');
                    $crate = floatval($cr['rate'] ?? 0);
                    if ($cid <= 0 || $cname === '') {
                        continue;
                    }
                    if (!lm_save_jeweller_category_rate($conn, $company_id, $party_id, $cid, $cname, $crate)) {
                        throw new Exception('Could not save category rate for ' . $cname);
                    }
                }

                $conn->commit();
                echo json_encode(['status' => 'success', 'party_id' => $party_id, 'message' => 'Party added']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'get_categories':
            echo json_encode(['status' => 'success', 'data' => lm_fetch_categories($conn, $company_id)]);
            exit;

        case 'save_category':
            $category_name = trim($_POST['category_name'] ?? '');
            $default_rate = floatval($_POST['default_rate'] ?? 0);
            $rate_basis = ($_POST['rate_basis'] ?? 'per_piece') === 'per_gram' ? 'per_gram' : 'per_piece';
            if ($category_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'Category name required']);
                exit;
            }
            $stmt = $conn->prepare(
                'INSERT INTO master_item_categories (firm_id, category_name, default_rate, rate_basis) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE default_rate = VALUES(default_rate), rate_basis = VALUES(rate_basis)'
            );
            $stmt->bind_param('isds', $company_id, $category_name, $default_rate, $rate_basis);
            if (!$stmt->execute()) {
                echo json_encode(['status' => 'error', 'message' => $conn->error]);
                $stmt->close();
                exit;
            }
            $id = $stmt->insert_id ?: 0;
            if ($id === 0) {
                $q = $conn->prepare('SELECT id FROM master_item_categories WHERE firm_id = ? AND category_name = ? LIMIT 1');
                $q->bind_param('is', $company_id, $category_name);
                $q->execute();
                $r = $q->get_result()->fetch_assoc();
                $id = (int) ($r['id'] ?? 0);
                $q->close();
            }
            $stmt->close();
            echo json_encode([
                'status' => 'success',
                'message' => 'Category saved',
                'data' => [
                    'id' => $id,
                    'category_name' => $category_name,
                    'default_rate' => $default_rate,
                    'rate_basis' => $rate_basis,
                ],
            ]);
            exit;

        case 'get_products':
            $jeweller_id = (int) ($_POST['jeweller_id'] ?? 0);
            echo json_encode([
                'status' => 'success',
                'data' => lm_fetch_jeweller_products($conn, $company_id, $jeweller_id),
                'category_rates' => lm_fetch_jeweller_category_rates($conn, $company_id, $jeweller_id),
            ]);
            exit;

        case 'save_product':
            $jeweller_id = (int) ($_POST['jeweller_id'] ?? 0);
            $product_name = trim($_POST['product_name'] ?? '');
            $rate = floatval($_POST['rate'] ?? 0);
            $category_id = (int) ($_POST['category_id'] ?? 0);
            if ($category_id <= 0) {
                $category_id = null;
            }
            if ($jeweller_id <= 0 || $product_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'Jeweller and product name required']);
                exit;
            }
            if ($category_id === null) {
                $stmt = $conn->prepare(
                    'INSERT INTO jeweller_product_rates (jeweller_id, product_name, rate, firm_id, category_id)
                     VALUES (?, ?, ?, ?, NULL)
                     ON DUPLICATE KEY UPDATE rate = VALUES(rate), category_id = VALUES(category_id), updated_at = NOW()'
                );
                $stmt->bind_param('isdi', $jeweller_id, $product_name, $rate, $company_id);
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO jeweller_product_rates (jeweller_id, product_name, rate, firm_id, category_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE rate = VALUES(rate), category_id = VALUES(category_id), updated_at = NOW()'
                );
                $stmt->bind_param('isdii', $jeweller_id, $product_name, $rate, $company_id, $category_id);
            }
            if (!$stmt->execute()) {
                echo json_encode(['status' => 'error', 'message' => $conn->error]);
                $stmt->close();
                exit;
            }
            $id = $stmt->insert_id ?: 0;
            if ($id === 0) {
                $q = $conn->prepare('SELECT id FROM jeweller_product_rates WHERE firm_id = ? AND jeweller_id = ? AND product_name = ? LIMIT 1');
                $q->bind_param('iis', $company_id, $jeweller_id, $product_name);
                $q->execute();
                $r = $q->get_result()->fetch_assoc();
                $id = (int) ($r['id'] ?? 0);
                $q->close();
            }
            $stmt->close();
            $cat_name = '';
            $rate_basis = 'per_piece';
            if ($category_id) {
                $cq = $conn->prepare('SELECT category_name, rate_basis FROM master_item_categories WHERE id = ? AND firm_id = ?');
                $cq->bind_param('ii', $category_id, $company_id);
                $cq->execute();
                if ($cr = $cq->get_result()->fetch_assoc()) {
                    $cat_name = $cr['category_name'];
                    $rate_basis = $cr['rate_basis'];
                }
                $cq->close();
            }
            echo json_encode([
                'status' => 'success',
                'message' => 'Product saved',
                'data' => [
                    'id' => $id,
                    'product_name' => $product_name,
                    'rate' => $rate,
                    'category_id' => $category_id,
                    'category_name' => $cat_name,
                    'rate_basis' => $rate_basis,
                ],
            ]);
            exit;

        case 'save_request':
            $request_id = (int) ($_POST['request_id'] ?? 0);
            $receipt_id = trim($_POST['receipt_id'] ?? '');
            $request_date = trim($_POST['request_date'] ?? '');
            $jeweller_id = (int) ($_POST['jeweller_id'] ?? 0);
            $mobile = trim($_POST['mobile'] ?? '');
            $logo = trim($_POST['logo'] ?? '');
            $box_no = trim($_POST['box_no'] ?? '');
            $received_amount = floatval($_POST['received_amount'] ?? 0);
            $payment_method = ge_normalize_payment_method($_POST['payment_method'] ?? 'Cash');
            if (!in_array($payment_method, ['Cash', 'UPI'], true)) {
                $payment_method = 'Cash';
            }
            $items_json = $_POST['items'] ?? '[]';
            $items = json_decode($items_json, true);
            if (!is_array($items)) {
                $items = [];
            }

            if ($jeweller_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Please select a jeweller']);
                exit;
            }
            if ($request_date === '') {
                $request_date = date('Y-m-d H:i:s');
            } else {
                $request_date = str_replace('T', ' ', $request_date);
                if (strlen($request_date) === 16) {
                    $request_date .= ':00';
                }
            }
            if (count($items) === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Add at least one item']);
                exit;
            }

            $old_received = 0.0;
            $old_method = 'Cash';
            $old_due = 0.0;
            $old_party_id = 0;
            if ($request_id > 0) {
                $oldRow = lm_fetch_logo_marking_header($conn, $request_id, $company_id);
                if ($oldRow) {
                    $old_received = (float) ($oldRow['received_amount'] ?? 0);
                    $old_method = (string) ($oldRow['payment_method'] ?? 'Cash');
                    $old_total = (float) ($oldRow['total_amount'] ?? 0);
                    $old_due = max(0, round($old_total - $old_received, 2));
                    $old_party_id = (int) ($oldRow['jeweller_id'] ?? 0);
                }
            }

            $receipt_id = lm_ensure_unique_receipt_id($conn, $company_id, $receipt_id, $request_id);
            $total_amount = 0.0;
            $total_weight = 0.0;
            $item_count = 0;
            foreach ($items as $it) {
                if (trim($it['item_name'] ?? '') === '') {
                    continue;
                }
                $item_count++;
                $total_amount += floatval($it['total_amount'] ?? 0);
                $total_weight += floatval($it['weight'] ?? 0);
            }
            $due_amount = max(0, round($total_amount - $received_amount, 2));
            $payment_status = lm_payment_status_for_amounts($total_amount, $received_amount);
            $narration = 'Logo marking';

            $conn->begin_transaction();
            try {
                if ($request_id <= 0) {
                    $dup = lm_find_duplicate_logo_marking_entry(
                        $conn,
                        $company_id,
                        $jeweller_id,
                        $box_no,
                        $logo,
                        $total_amount,
                        $item_count
                    );
                    if ($dup) {
                        $conn->rollback();
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Request already saved (duplicate prevented)',
                            'request_id' => $dup['id'],
                            'receipt_id' => $dup['receipt_id'],
                            'duplicate' => true,
                            'stats' => lm_fetch_dashboard_stats($conn, $company_id),
                            'next_receipt_id' => lm_next_receipt_id($conn, $company_id),
                        ]);
                        exit;
                    }
                    $receipt_id = lm_lock_receipt_id_for_new_entry($conn, $company_id, $receipt_id);
                }

                if ($request_id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE transactions SET
                            receipt_id = ?, date_of_transaction = ?, party_id = ?, contact_mobile = ?,
                            logo = ?, box_no = ?, gold_weight = ?, amount = ?, gold_amount = ?,
                            payment_amount = ?, payment_method = ?, due_amount = ?, payment_status = ?,
                            narration = ?, updated_at = NOW()
                         WHERE id = ? AND company_id = ? AND transaction_type = 'Logo_Marking'"
                    );
                    $stmt->bind_param(
                        'ssisssddddsdssii',
                        $receipt_id,
                        $request_date,
                        $jeweller_id,
                        $mobile,
                        $logo,
                        $box_no,
                        $total_weight,
                        $total_amount,
                        $total_amount,
                        $received_amount,
                        $payment_method,
                        $due_amount,
                        $payment_status,
                        $narration,
                        $request_id,
                        $company_id
                    );
                    $stmt->execute();
                    if ($stmt->affected_rows === 0) {
                        $stmt->close();
                        throw new Exception('Logo marking entry not found or already migrated');
                    }
                    $stmt->close();

                    $del = $conn->prepare('DELETE FROM logo_marking_items WHERE transaction_id = ? AND company_id = ?');
                    $del->bind_param('ii', $request_id, $company_id);
                    $del->execute();
                    $del->close();
                    $saved_id = $request_id;
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO transactions (
                            company_id, user_id, party_id, receipt_id, transaction_type, date_of_transaction,
                            gold_weight, amount, gold_amount, payment_amount, payment_method, payment_type,
                            due_amount, payment_status, logo, box_no, contact_mobile, narration, created_by
                         ) VALUES (?, ?, ?, ?, 'Logo_Marking', ?, ?, ?, ?, ?, ?, 'Payment_In', ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        'iiissddddssdssssi',
                        $company_id,
                        $user_id,
                        $jeweller_id,
                        $receipt_id,
                        $request_date,
                        $total_weight,
                        $total_amount,
                        $total_amount,
                        $received_amount,
                        $payment_method,
                        $due_amount,
                        $payment_status,
                        $logo,
                        $box_no,
                        $mobile,
                        $narration,
                        $user_id
                    );
                    $stmt->execute();
                    $saved_id = (int) $stmt->insert_id;
                    $stmt->close();
                }

                $ins = $conn->prepare(
                    'INSERT INTO logo_marking_items
                     (transaction_id, company_id, item_name, item_category, pieces, weight, purity, rate_per_piece, total_amount, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($items as $it) {
                    $item_name = trim($it['item_name'] ?? '');
                    if ($item_name === '') {
                        continue;
                    }
                    $item_category = trim($it['item_category'] ?? '');
                    $pieces = (int) ($it['pieces'] ?? 1);
                    $weight = floatval($it['weight'] ?? 0);
                    $purity = trim($it['purity'] ?? '');
                    if ($purity !== '' && !in_array($purity, lm_purity_options(), true)) {
                        $purity = '';
                    }
                    $rate = floatval($it['rate_per_piece'] ?? 0);
                    $line_total = floatval($it['total_amount'] ?? 0);
                    $status = in_array($it['status'] ?? '', ['Done', 'Cancelled'], true) ? $it['status'] : 'Pending';
                    $ins->bind_param('iissidsdds', $saved_id, $company_id, $item_name, $item_category, $pieces, $weight, $purity, $rate, $line_total, $status);
                    $ins->execute();
                }
                $ins->close();

                lm_sync_logo_marking_payment_balance($conn, $company_id, $old_received, $old_method, $received_amount, $payment_method);
                lm_sync_logo_marking_party_balance($conn, $jeweller_id, $old_due, $old_method, $due_amount, $payment_method, $old_party_id);

                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => $request_id > 0 ? 'Request updated' : 'Request saved',
                    'request_id' => $saved_id,
                    'receipt_id' => $receipt_id,
                    'stats' => lm_fetch_dashboard_stats($conn, $company_id),
                    'next_receipt_id' => lm_next_receipt_id($conn, $company_id),
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'get_request_by_receipt':
            $receipt_id = trim($_POST['receipt_id'] ?? '');
            $row = lm_fetch_logo_marking_header($conn, 0, $company_id, $receipt_id);
            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Receipt not found']);
                exit;
            }
            $row['items'] = lm_fetch_request_items($conn, (int) $row['id'], $company_id);
            echo json_encode(['status' => 'success', 'data' => $row]);
            exit;

        case 'get_request_by_id':
            $id = (int) ($_POST['request_id'] ?? 0);
            $row = lm_fetch_logo_marking_header($conn, $id, $company_id);
            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Request not found']);
                exit;
            }
            $row['items'] = lm_fetch_request_items($conn, (int) $row['id'], $company_id);
            echo json_encode(['status' => 'success', 'data' => $row]);
            exit;

        case 'delete_request':
            $id = (int) ($_POST['request_id'] ?? 0);
            $oldRow = $id > 0 ? lm_fetch_logo_marking_header($conn, $id, $company_id) : null;
            $conn->begin_transaction();
            try {
                $del_items = $conn->prepare('DELETE FROM logo_marking_items WHERE transaction_id = ? AND company_id = ?');
                $del_items->bind_param('ii', $id, $company_id);
                $del_items->execute();
                $del_items->close();

                $del_req = $conn->prepare(
                    "DELETE FROM transactions WHERE id = ? AND company_id = ? AND transaction_type = 'Logo_Marking'"
                );
                $del_req->bind_param('ii', $id, $company_id);
                $del_req->execute();
                $del_req->close();

                if ($oldRow) {
                    $old_total = (float) ($oldRow['total_amount'] ?? 0);
                    $old_received = (float) ($oldRow['received_amount'] ?? 0);
                    $old_due = max(0, round($old_total - $old_received, 2));
                    $old_party_id = (int) ($oldRow['jeweller_id'] ?? 0);
                    $old_method = (string) ($oldRow['payment_method'] ?? 'Cash');
                    lm_sync_logo_marking_payment_balance(
                        $conn,
                        $company_id,
                        $old_received,
                        $old_method,
                        0.0,
                        'Cash'
                    );
                    lm_sync_logo_marking_party_balance(
                        $conn,
                        0,
                        $old_due,
                        $old_method,
                        0.0,
                        'Cash',
                        $old_party_id
                    );
                }

                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Request deleted',
                    'stats' => lm_fetch_dashboard_stats($conn, $company_id),
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'list_requests':
            $start = $conn->real_escape_string($_POST['start_date'] ?? date('Y-m-d'));
            $end = $conn->real_escape_string($_POST['end_date'] ?? date('Y-m-d'));
            $search = trim($_POST['search'] ?? '');
            $searchEsc = $conn->real_escape_string($search);
            $offset = max(0, (int) ($_POST['offset'] ?? 0));
            $limit = min(50, max(1, (int) ($_POST['limit'] ?? 30)));
            $searchSql = '';
            if ($search !== '') {
                $like = "'%" . $searchEsc . "%'";
                $searchSql = " AND (p.party_name LIKE $like OR COALESCE(t.box_no, '') LIKE $like)";
            }

            try {
                $sql = "SELECT t.id, t.receipt_id, t.date_of_transaction AS request_date, t.contact_mobile AS mobile,
                               t.logo, t.box_no, t.amount AS total_amount, t.payment_amount AS received_amount,
                               t.payment_method, t.payment_status AS status,
                               p.party_name AS jeweller_name,
                               (SELECT COUNT(*) FROM logo_marking_items i WHERE i.transaction_id = t.id) AS item_count,
                               (SELECT COALESCE(SUM(i.pieces), 0) FROM logo_marking_items i WHERE i.transaction_id = t.id) AS total_pcs
                        FROM transactions t
                        LEFT JOIN parties p ON p.id = t.party_id
                        WHERE t.company_id = $company_id AND t.transaction_type = 'Logo_Marking'
                          AND DATE(t.date_of_transaction) BETWEEN '$start' AND '$end'
                          $searchSql
                        ORDER BY t.date_of_transaction DESC, t.id DESC
                        LIMIT $offset, $limit";
                $result = $conn->query($sql);
                $rows = [];
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $rows[] = $row;
                    }
                }
                echo json_encode(['status' => 'success', 'data' => $rows]);
            } catch (Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'get_dashboard_stats':
            $date = trim($_POST['date'] ?? date('Y-m-d'));
            echo json_encode([
                'status' => 'success',
                'data' => lm_fetch_dashboard_stats($conn, $company_id, $date),
            ]);
            exit;

        case 'get_next_receipt_id':
            echo json_encode([
                'status' => 'success',
                'receipt_id' => lm_next_receipt_id($conn, $company_id),
            ]);
            exit;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
            exit;
    }
}

$today = date('Y-m-d');
$stats = lm_fetch_dashboard_stats($conn, $company_id, $today);

$next_receipt_id = lm_next_receipt_id($conn, $company_id);
$categories = lm_fetch_categories($conn, $company_id);
$jewellery_items = lm_jewellery_item_names();
$page_title = 'Logo Marking';
?>

<style>
    .lm-compact-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: #374151; margin-bottom: 0.1rem; }
    .lm-compact-input { padding: 0.35rem 0.5rem; font-size: 0.75rem; font-weight: 600; }
    #lmPartyList .lm-party-table th,
    #lmPartyList .lm-party-table td { padding: 0.25rem 0.35rem; }
    #lmPartyList .lm-party-table thead th { position: sticky; top: 0; z-index: 2; background: #f8fafc; }
    #lmPartyList {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 80;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.375rem;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.14);
        max-height: 12rem;
        overflow-y: auto;
    }
    .lm-items-table { table-layout: fixed; width: 100%; min-width: 42rem; border-collapse: collapse; }
    .lm-items-table .lm-field {
        width: 100%;
        box-sizing: border-box;
        padding: 0.3rem 0.35rem;
        font-size: 0.6875rem;
        font-weight: 600;
        color: #111827;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.25rem;
        outline: none;
        min-height: 1.65rem;
    }
    .lm-items-table select.lm-field {
        padding-right: 1.1rem;
        cursor: pointer;
    }
    .lm-items-table .lm-field:focus {
        border-color: #818cf8;
        box-shadow: 0 0 0 1px #818cf8;
    }
    .lm-items-table th { font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; background: #f8fafc; padding: 0.35rem 0.25rem; white-space: nowrap; border-bottom: 1px solid #e2e8f0; }
    .lm-items-table td { padding: 0.2rem 0.15rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .lm-items-table input.lm-field::-webkit-outer-spin-button,
    .lm-items-table input.lm-field::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .lm-items-table input[type=number].lm-field { -moz-appearance: textfield; appearance: textfield; }
    .lm-num-field { font-variant-numeric: tabular-nums; }
    .lm-col-num { width: 3%; text-align: center; }
    .lm-col-cat { width: 14%; min-width: 4.25rem; }
    .lm-col-name { width: 14%; min-width: 4.5rem; }
    .lm-col-pcs { width: 7%; min-width: 2.75rem; }
    .lm-col-wt { width: 9%; min-width: 3.25rem; }
    .lm-col-pur { width: 11%; min-width: 4.25rem; }
    .lm-col-rate { width: 9%; min-width: 3.25rem; }
    .lm-col-amt { width: 9%; min-width: 3.25rem; }
    .lm-items-table tfoot tr {
        background: #f8fafc;
        border-top: 2px solid #e2e8f0;
    }
    .lm-items-table tfoot td {
        padding: 0.4rem 0.2rem;
        font-size: 0.6875rem;
        font-weight: 700;
        vertical-align: middle;
        border-bottom: none;
    }
    .lm-items-table tfoot .lm-total-label {
        text-align: right;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        padding-right: 0.25rem;
    }
    .lm-items-table tfoot .lm-total-cell {
        text-align: right;
        font-variant-numeric: tabular-nums;
        color: #334155;
    }
    .lm-items-table tfoot .lm-total-amt {
        color: #4338ca;
    }
    .lm-items-table .lm-amount {
        background: #f8fafc;
        color: #4338ca;
        font-weight: 700;
        cursor: default;
    }
    .lm-payment-bar {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        padding: 0.55rem 0.5rem;
        border-top: 1px solid #e5e7eb;
        background: #fff;
    }
    .lm-col-del { width: 3%; min-width: 1.25rem; text-align: center; }
    .lm-items-table .lm-pieces, .lm-items-table .lm-weight, .lm-items-table .lm-rate, .lm-items-table .lm-amount {
        text-align: right;
        font-variant-numeric: tabular-nums;
        padding-left: 0.15rem;
        padding-right: 0.15rem;
    }
    .lm-items-table .lm-purity {
        text-align: center;
        padding-left: 0.1rem;
        padding-right: 0.1rem;
    }
    .lm-items-table select.lm-purity {
        text-align-last: center;
    }
    .lm-payment-bar .lm-compact-input,
    .lm-payment-bar #lmBalanceDisplay {
        min-height: 1.85rem;
        display: flex;
        align-items: center;
    }
    .lm-payment-bar #lmBalanceDisplay {
        justify-content: flex-end;
    }
    .ge-txn-scroll { max-height: calc(100vh - 280px); min-height: 200px; overflow: auto; padding-right: 0.35rem; }
    .ge-txn-scroll thead th { position: sticky; top: 0; z-index: 5; background: #f8fafc; box-shadow: 0 1px 0 #e2e8f0; }
    .lm-list-table {
        table-layout: fixed;
        width: 100%;
        border-collapse: collapse;
    }
    .lm-list-table col.lm-serial-col { width: 5%; }
    .lm-list-table col.lm-id-col { width: 14%; }
    .lm-list-table col.lm-party-col { width: 24%; }
    .lm-list-table col.lm-box-col { width: 10%; }
    .lm-list-table col.lm-pcs-col { width: 7%; }
    .lm-list-table col.lm-amt-col { width: 12%; }
    .lm-list-table col.lm-status-col { width: 13%; }
    .lm-list-table col.lm-action-col { width: 15%; }
    .lm-list-table th,
    .lm-list-table td {
        padding: 0.35rem 0.25rem;
        overflow: hidden;
    }
    .lm-list-table .lm-action-col {
        overflow: visible;
        padding-left: 0.15rem !important;
        padding-right: 0.55rem !important;
        text-align: right;
        white-space: nowrap;
    }
    .lm-list-table .lm-action-col .lm-row-actions {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.2rem;
    }
    .lm-list-table .lm-action-col button {
        padding: 0.1rem 0.2rem;
        line-height: 1;
    }
    .lm-list-table thead th {
        font-size: 0.625rem;
        white-space: nowrap;
    }
    .lm-list-table .lm-party-col > div {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .stats-card-label { font-size: 10px; font-weight: 500; letter-spacing: 0.02em; color: rgb(100 116 139); text-transform: uppercase; }
    .stats-card-value { font-size: 1rem; font-weight: 600; color: rgb(51 65 85); font-variant-numeric: tabular-nums; }

    /* Compact Logo Marking modals (match app UI) */
    .lm-swal-popup {
        border-radius: 0.5rem !important;
        font-family: 'Inter', ui-sans-serif, system-ui, sans-serif !important;
        box-shadow: 0 10px 40px rgba(15, 23, 42, 0.18) !important;
    }
    .lm-swal-popup .swal2-title { padding: 0 !important; margin: 0 0 0.35rem !important; }
    .lm-swal-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
    .lm-swal-popup .swal2-actions { margin: 0.5rem 0 0 !important; gap: 0.35rem !important; }
    .lm-swal-popup .swal2-icon { display: none !important; }
    .lm-swal-popup .swal2-validation-message {
        font-size: 10px !important;
        margin: 0.35rem 0 0 !important;
        padding: 0.25rem 0.4rem !important;
    }
    .lm-swal-btn {
        font-size: 10px !important;
        padding: 0.35rem 0.7rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.03em !important;
        border-radius: 0.375rem !important;
        box-shadow: none !important;
    }
    .lm-swal-btn-primary { background: #4f46e5 !important; }
    .lm-swal-btn-cancel { background: #64748b !important; }
    .lm-modal-input {
        width: 100%;
        padding: 0.25rem 0.5rem;
        height: 1.75rem;
        font-size: 11px;
        font-weight: 600;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.25rem;
        outline: none;
    }
    .lm-modal-input:focus {
        border-color: #818cf8;
        box-shadow: 0 0 0 1px #818cf8;
    }
    #lmForm input[type=number]::-webkit-outer-spin-button,
    #lmForm input[type=number]::-webkit-inner-spin-button,
    .lm-modal-input::-webkit-outer-spin-button,
    .lm-modal-input::-webkit-inner-spin-button,
    .lm-swal-popup input[type=number]::-webkit-outer-spin-button,
    .lm-swal-popup input[type=number]::-webkit-inner-spin-button,
    .swal2-container input[type=number]::-webkit-outer-spin-button,
    .swal2-container input[type=number]::-webkit-inner-spin-button {
        -webkit-appearance: none !important;
        appearance: none !important;
        margin: 0 !important;
        display: none !important;
    }
    #lmForm input[type=number],
    .lm-modal-input[type=number],
    .lm-swal-popup input[type=number],
    .swal2-container input[type=number] {
        -moz-appearance: textfield !important;
        appearance: textfield !important;
    }
    .lm-modal-label {
        display: block;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
        margin-bottom: 0.15rem;
    }
    .lm-modal-link {
        font-size: 9px;
        font-weight: 700;
        color: #4f46e5;
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
    }
    .lm-modal-link:hover { color: #4338ca; text-decoration: underline; }
</style>

<div class="w-full min-w-0 px-1 pb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3">
        <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50">
            <p class="stats-card-label">Total amount</p>
            <p class="stats-card-value" id="lmStatTotal">₹<?= lm_format_inr((float) ($stats['today_total'] ?? 0)) ?></p>
        </div>
        <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50">
            <p class="stats-card-label">Received amount</p>
            <p class="stats-card-value text-emerald-700" id="lmStatReceived">₹<?= lm_format_inr((float) ($stats['today_received'] ?? 0)) ?></p>
        </div>
        <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50">
            <p class="stats-card-label">Cash in hand</p>
            <p class="stats-card-value text-amber-700" id="lmStatCash">₹<?= lm_format_inr((float) ($stats['cash_in_hand'] ?? 0)) ?></p>
        </div>
        <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50">
            <p class="stats-card-label">Bank account</p>
            <p class="stats-card-value text-blue-700" id="lmStatBank">₹<?= lm_format_inr((float) ($stats['bank_balance'] ?? 0)) ?></p>
        </div>
    </div>

    <div class="flex flex-col lg:flex-row gap-3 min-w-0">
        <div class="bg-white rounded-lg shadow-md border border-gray-200 lg:flex-[1_1_58%] overflow-visible">
            <form id="lmForm" onsubmit="return false;">
                <input type="hidden" name="request_id" id="lmRequestId" value="">
                <input type="hidden" name="jeweller_id" id="lmJewellerId" value="">

                <div class="bg-indigo-50 px-3 py-1 border-b border-indigo-100 flex items-center justify-between">
                    <h3 class="text-xs font-bold text-indigo-800 flex items-center">
                        <i class="fas fa-stamp mr-1.5"></i> Logo Marking Request
                        <span id="lmEditBadge" class="ml-2 text-orange-600 hidden text-[10px]">(Edit)</span>
                    </h3>
                    <button type="button" id="lmAddCategoryBtn" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 uppercase">
                        <i class="fas fa-plus-circle mr-0.5"></i> Category
                    </button>
                </div>

                <div class="p-2 grid grid-cols-12 gap-1.5">
                    <div class="relative col-span-3">
                        <label class="lm-compact-label block">Receipt ID</label>
                        <div class="relative">
                            <input type="text" id="lmReceiptId" name="receipt_id" readonly
                                class="block w-full pl-2 pr-7 py-1.5 text-xs font-bold bg-white border border-gray-200 rounded lm-compact-input cursor-pointer"
                                value="<?= htmlspecialchars($next_receipt_id) ?>">
                            <button type="button" id="lmShowReceiptListBtn" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-indigo-600">
                                <i class="fas fa-history text-xs"></i>
                            </button>
                        </div>
                        <div id="lmReceiptList" class="hidden absolute bg-white border rounded shadow-lg max-h-60 overflow-y-auto text-[10px] w-full"></div>
                    </div>
                    <div class="col-span-3">
                        <label class="lm-compact-label block">Date &amp; Time</label>
                        <input type="datetime-local" name="request_date" id="lmRequestDate"
                            class="block w-full border border-gray-200 rounded lm-compact-input" required>
                    </div>
                    <div class="relative col-span-6">
                        <label class="lm-compact-label block flex justify-between">
                            <span>Jeweller</span>
                            <button type="button" id="lmAddPartyBtn" class="text-indigo-600 hover:text-indigo-800"><i class="fas fa-plus-circle"></i> Add</button>
                        </label>
                        <input type="text" id="lmJewellerName" autocomplete="off"
                            class="block w-full border border-gray-200 rounded lm-compact-input" placeholder="Search jeweller...">
                        <div id="lmPartyList" class="hidden absolute bg-white border rounded shadow-lg max-h-48 overflow-y-auto text-[10px] w-full"></div>
                    </div>
                    <div class="col-span-3">
                        <label class="lm-compact-label block">Mobile</label>
                        <input type="text" name="mobile" id="lmMobile" class="block w-full border border-gray-200 rounded lm-compact-input" placeholder="Mobile no">
                    </div>
                    <div class="col-span-3">
                        <label class="lm-compact-label block">Logo</label>
                        <input type="text" name="logo" id="lmLogo" class="block w-full border border-gray-200 rounded lm-compact-input" placeholder="Logo mark">
                    </div>
                    <div class="col-span-3">
                        <label class="lm-compact-label block">Box No</label>
                        <input type="text" name="box_no" id="lmBoxNo" class="block w-full border border-gray-200 rounded lm-compact-input" placeholder="Box number">
                    </div>
                    <div class="col-span-3 flex items-end">
                        <button type="button" id="lmAddItemBtn" tabindex="-1" title="Add item row (Alt+A)"
                            class="w-full py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded">
                            <i class="fas fa-plus mr-1"></i> Add Item Row
                        </button>
                    </div>
                </div>

                <div class="px-2 pb-2 overflow-x-auto">
                    <table class="w-full lm-items-table border border-gray-200 rounded">
                        <thead>
                            <tr>
                                <th class="lm-col-num">#</th>
                                <th class="lm-col-cat">Category</th>
                                <th class="lm-col-name">Item Name</th>
                                <th class="lm-col-pcs">Pcs</th>
                                <th class="lm-col-wt">Wt (g)</th>
                                <th class="lm-col-pur">Purity</th>
                                <th class="lm-col-rate">Rate</th>
                                <th class="lm-col-amt">Amt</th>
                                <th class="lm-col-del"></th>
                            </tr>
                        </thead>
                        <tbody id="lmItemsBody"></tbody>
                        <tfoot>
                            <tr class="lm-items-total-row">
                                <td colspan="3" class="lm-total-label">Total</td>
                                <td id="lmTotalPcs" class="lm-total-cell lm-col-pcs">0</td>
                                <td id="lmTotalWeight" class="lm-total-cell lm-col-wt">0</td>
                                <td class="lm-col-pur"></td>
                                <td class="lm-col-rate"></td>
                                <td id="lmTotalAmount" class="lm-total-cell lm-total-amt lm-col-amt">0</td>
                                <td class="lm-col-del"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="lm-payment-bar">
                    <input type="hidden" id="lmGrandTotalInput" value="0">
                    <input type="hidden" id="lmGrandTotalReadonly" value="0">
                    <div>
                        <label class="lm-compact-label block">Received Amount (₹)</label>
                        <input type="text" inputmode="decimal" name="received_amount" id="lmReceivedAmount"
                            class="block w-full border border-gray-200 rounded lm-compact-input lm-num-field font-bold" placeholder="0">
                    </div>
                    <div>
                        <label class="lm-compact-label block">Payment Method</label>
                        <select name="payment_method" id="lmPaymentMethod" class="block w-full border border-gray-200 rounded lm-compact-input font-bold">
                            <option value="Cash" selected>Cash</option>
                            <option value="UPI">UPI</option>
                        </select>
                    </div>
                    <div class="flex flex-col justify-end">
                        <label class="lm-compact-label block">Balance</label>
                        <div id="lmBalanceDisplay" class="lm-compact-input border border-gray-200 rounded bg-slate-50 font-bold text-slate-700 text-right">₹0</div>
                    </div>
                </div>

                <div class="px-2 py-2 border-t bg-gray-50 flex justify-end gap-2">
                    <button type="button" id="lmResetBtn" class="px-3 py-1.5 text-xs font-bold text-gray-600 bg-white border rounded hover:bg-gray-100">Reset</button>
                    <button type="button" id="lmDeleteBtn" class="px-3 py-1.5 text-xs font-bold text-red-600 bg-white border border-red-200 rounded hover:bg-red-50 hidden">Delete</button>
                    <button type="button" id="lmSaveBtn" class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 rounded hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-save mr-1"></i> Save
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-md border border-gray-200 lg:flex-[1_1_42%] overflow-hidden min-w-0">
            <div class="bg-slate-50 px-3 py-2 border-b flex flex-wrap items-center gap-2">
                <h3 class="text-xs font-bold text-slate-700 w-full sm:w-auto sm:flex-1"><i class="fas fa-list mr-1"></i> Recent Requests</h3>
                <input type="text" id="lmListSearch" placeholder="Search party or box no…"
                    class="text-[10px] border rounded px-2 py-0.5 min-w-[8rem] flex-1 sm:flex-none sm:min-w-[9rem]">
                <input type="date" id="lmStartDate" value="<?= $today ?>" class="text-[10px] border rounded px-1 py-0.5">
                <span class="text-[10px] text-gray-400">to</span>
                <input type="date" id="lmEndDate" value="<?= $today ?>" class="text-[10px] border rounded px-1 py-0.5">
                <button type="button" id="lmFilterBtn" class="text-[10px] px-2 py-0.5 bg-indigo-600 text-white rounded font-bold">Go</button>
            </div>
            <div class="ge-txn-scroll">
                <table class="lm-list-table text-[10px]">
                    <colgroup>
                        <col class="lm-serial-col">
                        <col class="lm-id-col">
                        <col class="lm-party-col">
                        <col class="lm-box-col">
                        <col class="lm-pcs-col">
                        <col class="lm-amt-col">
                        <col class="lm-status-col">
                        <col class="lm-action-col">
                    </colgroup>
                    <thead>
                        <tr class="text-left text-gray-500 uppercase">
                            <th class="lm-serial-col text-center">#</th>
                            <th class="lm-id-col">Id</th>
                            <th class="lm-party-col">Jeweller</th>
                            <th class="lm-box-col">Box</th>
                            <th class="lm-pcs-col text-center">Pcs</th>
                            <th class="lm-amt-col">Amt</th>
                            <th class="lm-status-col">Status</th>
                            <th class="lm-action-col"></th>
                        </tr>
                    </thead>
                    <tbody id="lmRecentList"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

ob_start();
?>
<script>
    window.LM_CONFIG = {
        nextReceiptId: <?= json_encode($next_receipt_id) ?>,
        categories: <?= json_encode($categories) ?>,
        jewelleryItems: <?= json_encode($jewellery_items) ?>,
        purityOptions: <?= json_encode(lm_purity_options()) ?>,
        products: []
    };
</script>
<script src="js/logo_marking.js?v=<?= filemtime(__DIR__ . '/js/logo_marking.js') ?>"></script>
<?php
$additional_scripts = ob_get_clean();
include __DIR__ . '/components/layout.php';
