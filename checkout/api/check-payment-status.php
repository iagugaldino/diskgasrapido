<?php
/**
 * check-payment-status.php
 * Verifica status do pagamento consultando o arquivo de contexto do pedido.
 * O webhook-ghostspay.php atualiza o status quando o pagamento é confirmado.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

define('LOG_DIR', __DIR__ . '/../logs');

$order = trim($_GET['order'] ?? '');

if (!$order) {
    echo json_encode(['paid' => false, 'error' => 'order obrigatório']);
    exit;
}

// Sanitiza — só letras, números, hífens e underscore
$order = preg_replace('/[^A-Za-z0-9\-_]/', '', $order);

// Tenta primeiro pelo índice order_id → transaction_id
$orderFile  = LOG_DIR . '/order_' . $order . '.json'; // caso seja transaction_id direto
$indexFile  = LOG_DIR . '/idx_' . $order . '.json';

if (!file_exists($orderFile) && file_exists($indexFile)) {
    $idx = json_decode(file_get_contents($indexFile), true);
    $txnId = $idx['transaction_id'] ?? null;
    if ($txnId) {
        $orderFile = LOG_DIR . '/order_' . $txnId . '.json';
    }
}

// Último recurso: varredura (para pedidos antigos sem índice)
if (!file_exists($orderFile)) {
    $files = glob(LOG_DIR . '/order_*.json') ?: [];
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (($data['order_id'] ?? '') === $order || ($data['transaction_id'] ?? '') === $order) {
            $orderFile = $f;
            break;
        }
    }
}

if (!file_exists($orderFile)) {
    echo json_encode(['paid' => false, 'error' => 'pedido não encontrado']);
    exit;
}

$orderData = json_decode(file_get_contents($orderFile), true);
$paid      = ($orderData['status'] ?? '') === 'paid';

echo json_encode([
    'paid'   => $paid,
    'status' => $orderData['status'] ?? 'pending',
    'order'  => $order,
]);