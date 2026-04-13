<?php
/**
 * process-checkout.php
 * Processa o pedido: chama GhostsPay para gerar transação PIX/Cartão
 * e notifica Utmify (venda pendente).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ══════════════════════════════════════════════
//  CONFIGURAÇÕES
// ══════════════════════════════════════════════
define('GHOSTSPAY_SECRET',  'sk_auto_IcoA9n92RSUwO91cfmSaRwp2JK0dXFpV');
define('GHOSTSPAY_PUBLIC',  'pk_auto_6xR7pSzf6vBTRKYygm064PbamvLsL6kt');
define('GHOSTSPAY_BASE',    'https://api.ghostspaysv1.com');

define('UTMIFY_TOKEN',      'v4EDoWyq83Zd4xDfEtQ3rZklw6Tcg284QiNP');
define('UTMIFY_BASE',       'https://api.utmify.com.br/api-credentials/orders');
define('UTMIFY_PLATFORM',   'GhostsPay');

define('LOG_DIR',           __DIR__ . '/../logs');

// ══════════════════════════════════════════════
//  LOGGER
// ══════════════════════════════════════════════
function logWrite(string $level, string $context, $data): void
{
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    $file    = LOG_DIR . '/' . date('Y-m-d') . '.log';
    $time    = date('Y-m-d H:i:s');
    $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $line    = "[{$time}] [{$level}] [{$context}] {$payload}" . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// ══════════════════════════════════════════════
//  HTTP HELPER
// ══════════════════════════════════════════════
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
    $resp    = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'body'      => $resp,
        'error'     => $err,
        'decoded'   => $resp ? json_decode($resp, true) : null,
    ];
}

// ══════════════════════════════════════════════
//  VALIDAÇÃO DO INPUT
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    logWrite('ERROR', 'INPUT', 'JSON inválido recebido: ' . $raw);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
    exit;
}

logWrite('INFO', 'INPUT', $data);

// Campos obrigatórios
$required = ['customer_name', 'email', 'phone', 'payment_method', 'total_final'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        logWrite('ERROR', 'VALIDATION', "Campo obrigatório ausente: {$field}");
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => "Campo obrigatório ausente: {$field}"]);
        exit;
    }
}

// ══════════════════════════════════════════════
//  MONTAR DADOS LIMPOS
// ══════════════════════════════════════════════
$customerName   = trim($data['customer_name']);
$email          = trim($data['email']);
$phone          = preg_replace('/\D/', '', $data['phone']);
$cpf            = preg_replace('/\D/', '', $data['cpf'] ?? '');
$paymentMethod  = $data['payment_method']; // 'pix' | 'card'
$totalFinal     = floatval($data['total_final']);
$totalCents     = intval(round($totalFinal * 100));
$items          = $data['items'] ?? [];
$orderId        = 'ORD-' . strtoupper(uniqid());

// UTMs vindas do front (capturadas no JS da URL)
$utmSource   = $data['utm_source']   ?? null;
$utmMedium   = $data['utm_medium']   ?? null;
$utmCampaign = $data['utm_campaign'] ?? null;
$utmContent  = $data['utm_content']  ?? null;
$utmTerm     = $data['utm_term']     ?? null;
$src         = $data['src']          ?? null;
$sck         = $data['sck']          ?? null;

// Montar produtos para GhostsPay
$gpProducts = [];
foreach ($items as $item) {
    $gpProducts[] = [
        'product_name' => $item['name'] ?? 'Produto',
        'quantity'     => intval($item['quantity'] ?? 1),
        'value'        => floatval($item['unit_price'] ?? 0),
    ];
}
if (empty($gpProducts)) {
    $gpProducts[] = [
        'product_name' => 'Pedido',
        'quantity'     => 1,
        'value'        => $totalFinal,
    ];
}

// Montar produtos para Utmify
$utmProducts = [];
foreach ($items as $item) {
    $itemId    = 'PROD-' . ($item['id'] ?? uniqid());
    $itemCents = intval(round(floatval($item['unit_price'] ?? 0) * 100));
    $utmProducts[] = [
        'id'        => (string) $itemId,
        'name'      => $item['name'] ?? 'Produto',
        'planId'    => null,
        'planName'  => null,
        'quantity'  => intval($item['quantity'] ?? 1),
        'priceInCents' => $itemCents,
    ];
}
if (empty($utmProducts)) {
    $utmProducts[] = [
        'id'           => 'PROD-1',
        'name'         => 'Pedido',
        'planId'       => null,
        'planName'     => null,
        'quantity'     => 1,
        'priceInCents' => $totalCents,
    ];
}

$nowUtc = gmdate('Y-m-d H:i:s');

// ══════════════════════════════════════════════
//  1. CHAMAR GHOSTSPAY — Gerar Transação
// ══════════════════════════════════════════════
// URL correta do webhook — baseada no diretório real do script, não do checkout.html
$webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/api/webhook-ghostspay.php';

// Monta payload base sem UTMs
$gpPayload = [
    'provider'            => 'Checkout',
    'client_name'         => $customerName,
    'client_email'        => $email,
    'client_document'     => $cpf ?: '00000000000',
    'client_mobile_phone' => $phone,
    'external_ref'        => $orderId,
    'post_back_url'       => $webhookUrl,
    'products'            => $gpProducts,
];

// Só adiciona UTMs se forem strings não-vazias (GhostsPay rejeita null)
if (!empty($utmSource))   $gpPayload['utm_source']   = (string) $utmSource;
if (!empty($utmMedium))   $gpPayload['utm_medium']   = (string) $utmMedium;
if (!empty($utmCampaign)) $gpPayload['utm_campaign'] = (string) $utmCampaign;
if (!empty($utmContent))  $gpPayload['utm_content']  = (string) $utmContent;
if (!empty($utmTerm))     $gpPayload['utm_term']     = (string) $utmTerm;

logWrite('INFO', 'GHOSTSPAY_REQUEST', $gpPayload);

$gpResult = httpPost(
    GHOSTSPAY_BASE . '/api/generate-transaction',
    [
        'Content-Type: application/json',
        'X-Secret-Key: ' . GHOSTSPAY_SECRET,
        'X-Public-Key: '  . GHOSTSPAY_PUBLIC,
    ],
    $gpPayload
);

logWrite('INFO', 'GHOSTSPAY_RESPONSE', [
    'http_code' => $gpResult['http_code'],
    'body'      => $gpResult['body'],
    'curl_error'=> $gpResult['error'],
]);

if ($gpResult['error']) {
    logWrite('ERROR', 'GHOSTSPAY_CURL', $gpResult['error']);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Erro ao conectar com o gateway de pagamento.']);
    exit;
}

$gpData = $gpResult['decoded'];

// A GhostsPay retorna direto { transaction_id, status, pix: { code, qr_code_url } }
// (não usa o formato { success: true, data: { ... } } da documentação)
$hasTransaction = !empty($gpData['transaction_id']) || !empty($gpData['payment_id']);

if (!$hasTransaction) {
    logWrite('ERROR', 'GHOSTSPAY_FAIL', $gpData);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $gpData['message'] ?? 'Erro ao gerar transação.']);
    exit;
}

$transactionId = $gpData['transaction_id'] ?? ($gpData['payment_id'] ?? $orderId);

// PIX: a API retorna pix.code (copia-e-cola) e pix.qr_code_url (imagem relativa)
$pixCode      = $gpData['pix']['code']          ?? null;
$pixQrUrl     = $gpData['pix']['qr_code_url']   ?? null;  // caminho relativo no servidor deles
$pixQrImage   = $gpData['pix']['qr_code_image'] ?? null;
$pixExpires   = $gpData['pix']['expiration_date'] ?? null;

// Monta URL absoluta da imagem do QR Code
$pixQrAbsUrl = null;
if ($pixQrUrl) {
    $pixQrAbsUrl = 'https://api.ghostspaysv1.com' . $pixQrUrl;
}

logWrite('INFO', 'GHOSTSPAY_OK', "Transação criada: {$transactionId}");

// ══════════════════════════════════════════════
//  2. UTMIFY — delegado ao webhook
//  O GhostsPay dispara transaction.pending quase
//  imediatamente após a criação. O webhook-ghostspay.php
//  é quem envia waiting_payment e paid para a Utmify,
//  evitando duplicação.
// ══════════════════════════════════════════════
logWrite('INFO', 'UTMIFY_DELEGATED', "Notificação Utmify será feita pelo webhook. OrderId: {$orderId}");

// ══════════════════════════════════════════════
//  Salva contexto do pedido em session/arquivo
//  para o webhook confirmar depois
// ══════════════════════════════════════════════
$orderContext = [
    'order_id'        => $orderId,
    'transaction_id'  => $transactionId,
    'customer_name'   => $customerName,
    'email'           => $email,
    'phone'           => $phone,
    'cpf'             => $cpf,
    'payment_method'  => $paymentMethod,
    'total_cents'     => $totalCents,
    'total_final'     => $totalFinal,
    'products'        => $utmProducts,
    'items_raw'       => $items,
    'utm_source'      => $utmSource,
    'utm_medium'      => $utmMedium,
    'utm_campaign'    => $utmCampaign,
    'utm_content'     => $utmContent,
    'utm_term'        => $utmTerm,
    'src'             => $src,
    'sck'             => $sck,
    'created_at'      => $nowUtc,
    // Dados PIX para a página /pagar
    'pix_code'        => $pixCode    ?? null,
    'pix_qr_url'      => $pixQrAbsUrl ?? null,
    'pix_expires'     => $pixExpires  ?? null,
    'payment_id'      => $gpData['payment_id'] ?? null,
];

$orderFile = LOG_DIR . '/order_' . $transactionId . '.json';
file_put_contents($orderFile, json_encode($orderContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Índice rápido por order_id → transaction_id (para o polling de status)
$indexFile = LOG_DIR . '/idx_' . $orderId . '.json';
file_put_contents($indexFile, json_encode(['transaction_id' => $transactionId], JSON_UNESCAPED_UNICODE));

logWrite('INFO', 'ORDER_SAVED', "Contexto salvo: {$orderFile}");

// ══════════════════════════════════════════════
//  RESPOSTA PARA O FRONTEND
// ══════════════════════════════════════════════
$response = [
    'success'        => true,
    'order_number'   => $orderId,
    'transaction_id' => $transactionId,
];

if ($paymentMethod === 'pix') {
    // Redireciona para /pagar — os dados do PIX já ficaram salvos no arquivo de contexto
    // e também passamos inline para o JS poder salvar no localStorage
    $response['redirect_url'] = '/pagar?order=' . urlencode($orderId);
    $response['pix'] = [
        'qr_code'     => $pixCode,
        'qr_code_url' => $pixQrAbsUrl,
        'expires_at'  => $pixExpires,
    ];
}

logWrite('INFO', 'RESPONSE_OK', $response);
echo json_encode($response);