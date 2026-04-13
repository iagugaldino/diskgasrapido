<?php
/**
 * webhook-ghostspay.php
 *
 * Recebe notificações do GhostsPay e envia para a Utmify.
 *
 * ─ transaction.pending → Utmify: waiting_payment
 * ─ transaction.paid    → Utmify: paid  + marca arquivo como paid
 *
 * O process-checkout.php NÃO envia mais nada para a Utmify,
 * evitando duplicação de eventos.
 */

header('Content-Type: application/json; charset=utf-8');

define('UTMIFY_TOKEN',    'v4EDoWyq83Zd4xDfEtQ3rZklw6Tcg284QiNP');
define('UTMIFY_BASE',     'https://api.utmify.com.br/api-credentials/orders');
define('UTMIFY_PLATFORM', 'GhostsPay');
define('LOG_DIR',         __DIR__ . '/../logs');

// ── Logger
function logWrite(string $level, string $context, $data): void
{
    if (!is_dir(LOG_DIR)) mkdir(LOG_DIR, 0755, true);
    $file    = LOG_DIR . '/' . date('Y-m-d') . '.log';
    $time    = date('Y-m-d H:i:s');
    $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($file, "[{$time}] [{$level}] [{$context}] {$payload}" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ── HTTP POST
function httpPost(string $url, array $headers, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return [
        'http_code' => $httpCode,
        'body'      => $resp,
        'error'     => $err,
        'decoded'   => $resp ? json_decode($resp, true) : null,
    ];
}

// ── Receber payload
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

logWrite('INFO', 'WEBHOOK_RECEIVED', $raw ?: '(empty)');

if (!$data) {
    logWrite('ERROR', 'WEBHOOK_PARSE', 'JSON invalido');
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$event         = $data['event']                                          ?? '';
$transactionId = $data['data']['id'] ?? $data['data']['transaction_id'] ?? '';
$status        = strtolower($data['data']['status'] ?? '');
$amount        = floatval($data['data']['amount'] ?? $data['data']['total_value'] ?? 0);
$paidAt        = $data['data']['paidAt'] ?? $data['data']['paid_at']    ?? null;

logWrite('INFO', 'WEBHOOK_EVENT', "event={$event} txn={$transactionId} status={$status}");

// ── Determinar acao
$isPending = ($event === 'transaction.pending' || $status === 'pending');
$isPaid    = ($event === 'transaction.paid'    || $status === 'paid');

if (!$isPending && !$isPaid) {
    logWrite('INFO', 'WEBHOOK_SKIP', "Evento nao tratado: {$event} / status: {$status}");
    http_response_code(200);
    echo json_encode(['ok' => true, 'msg' => 'ignored']);
    exit;
}

// ── Carregar contexto do pedido
$orderFile = LOG_DIR . '/order_' . $transactionId . '.json';
$order     = null;

if (file_exists($orderFile)) {
    $order = json_decode(file_get_contents($orderFile), true);
    logWrite('INFO', 'WEBHOOK_ORDER_LOADED', "order_id=" . ($order['order_id'] ?? '?'));
} else {
    logWrite('WARN', 'WEBHOOK_ORDER_NOT_FOUND', "Arquivo nao encontrado: {$orderFile}");
}

// ── Dados do pedido
$orderId       = $order['order_id']       ?? ('TXN-' . $transactionId);
$customerName  = $order['customer_name']  ?? ($data['data']['customer']['name']     ?? 'Cliente');
$email         = $order['email']          ?? ($data['data']['customer']['email']    ?? '');
$phone         = $order['phone']          ?? ($data['data']['customer']['phone']    ?? '');
$cpf           = $order['cpf']            ?? ($data['data']['customer']['document'] ?? '');
$totalCents    = $order['total_cents']    ?? intval(round($amount * 100));
$products      = $order['products']       ?? [['id'=>'PROD-1','name'=>'Pedido','planId'=>null,'planName'=>null,'quantity'=>1,'priceInCents'=>$totalCents]];
$paymentMethod = $order['payment_method'] ?? 'pix';
$createdAt     = $order['created_at']     ?? gmdate('Y-m-d H:i:s');

$utmPaymentMethod = ($paymentMethod === 'card') ? 'credit_card' : 'pix';

$trackingParams = [
    'src'          => $order['src']          ?? null,
    'sck'          => $order['sck']          ?? null,
    'utm_source'   => $order['utm_source']   ?? null,
    'utm_campaign' => $order['utm_campaign'] ?? null,
    'utm_medium'   => $order['utm_medium']   ?? null,
    'utm_content'  => $order['utm_content']  ?? null,
    'utm_term'     => $order['utm_term']     ?? null,
];

$commission = [
    'totalPriceInCents'     => $totalCents,
    'gatewayFeeInCents'     => 0,
    'userCommissionInCents' => $totalCents,
    'currency'              => 'BRL',
];

// ════════════════════════════════════════════════════════
//  PENDING → Utmify: waiting_payment
// ════════════════════════════════════════════════════════
if ($isPending) {
    $utmPayload = [
        'orderId'            => $orderId,
        'platform'           => UTMIFY_PLATFORM,
        'paymentMethod'      => $utmPaymentMethod,
        'status'             => 'waiting_payment',
        'createdAt'          => $createdAt,
        'approvedDate'       => null,
        'refundedAt'         => null,
        'customer'           => [
            'name'     => $customerName,
            'email'    => $email,
            'phone'    => $phone,
            'document' => $cpf ?: null,
            'country'  => 'BR',
        ],
        'products'           => $products,
        'trackingParameters' => $trackingParams,
        'commission'         => $commission,
        'isTest'             => false,
    ];

    logWrite('INFO', 'UTMIFY_PENDING_REQUEST', $utmPayload);
    $r = httpPost(UTMIFY_BASE, ['Content-Type: application/json', 'x-api-token: ' . UTMIFY_TOKEN], $utmPayload);
    logWrite('INFO', 'UTMIFY_PENDING_RESPONSE', ['http_code' => $r['http_code'], 'body' => $r['body'], 'curl_error' => $r['error']]);

    if ($r['error'])                  logWrite('ERROR', 'UTMIFY_PENDING_CURL', $r['error']);
    elseif ($r['http_code'] !== 200)  logWrite('ERROR', 'UTMIFY_PENDING_FAIL', $r['decoded'] ?? $r['body']);
    else                              logWrite('INFO',  'UTMIFY_PENDING_OK', "waiting_payment enviado. OrderId: {$orderId}");

    http_response_code(200);
    echo json_encode(['ok' => true, 'action' => 'pending_sent']);
    exit;
}

// ════════════════════════════════════════════════════════
//  PAID → Utmify: paid  +  atualiza arquivo
// ════════════════════════════════════════════════════════
$approvedDate = $paidAt
    ? gmdate('Y-m-d H:i:s', strtotime($paidAt))
    : gmdate('Y-m-d H:i:s');

$utmPayload = [
    'orderId'            => $orderId,
    'platform'           => UTMIFY_PLATFORM,
    'paymentMethod'      => $utmPaymentMethod,
    'status'             => 'paid',
    'createdAt'          => $createdAt,
    'approvedDate'       => $approvedDate,
    'refundedAt'         => null,
    'customer'           => [
        'name'     => $customerName,
        'email'    => $email,
        'phone'    => $phone,
        'document' => $cpf ?: null,
        'country'  => 'BR',
    ],
    'products'           => $products,
    'trackingParameters' => $trackingParams,
    'commission'         => $commission,
    'isTest'             => false,
];

logWrite('INFO', 'UTMIFY_PAID_REQUEST', $utmPayload);
$r = httpPost(UTMIFY_BASE, ['Content-Type: application/json', 'x-api-token: ' . UTMIFY_TOKEN], $utmPayload);
logWrite('INFO', 'UTMIFY_PAID_RESPONSE', ['http_code' => $r['http_code'], 'body' => $r['body'], 'curl_error' => $r['error']]);

if ($r['error'])                  logWrite('ERROR', 'UTMIFY_PAID_CURL', $r['error']);
elseif ($r['http_code'] !== 200)  logWrite('ERROR', 'UTMIFY_PAID_FAIL', $r['decoded'] ?? $r['body']);
else                              logWrite('INFO',  'UTMIFY_PAID_OK', "paid enviado. OrderId: {$orderId}");

// Marca arquivo como pago (para o polling de status na pagina /pagar)
if (file_exists($orderFile)) {
    $orderData            = json_decode(file_get_contents($orderFile), true) ?? [];
    $orderData['status']  = 'paid';
    $orderData['paid_at'] = $approvedDate;
    file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    logWrite('INFO', 'ORDER_STATUS_UPDATED', "status=paid gravado em {$orderFile}");
}

http_response_code(200);
echo json_encode(['ok' => true, 'action' => 'paid_sent']);