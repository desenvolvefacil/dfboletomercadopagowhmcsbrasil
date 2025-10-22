<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

define('PAYMENT_METHOD_MP_BOLETO', 'dfboletomercadopago');


$gatewayParams = getGatewayVariables(PAYMENT_METHOD_MP_BOLETO);

$access_token = trim($gatewayParams['AccessTokenProducao'] ?? '');

$input = file_get_contents('php://input');
$notification = json_decode($input, true);

logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($notification , JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'Notificação Recebida');

if (isset($notification['data']['id']) && ($notification['type'] ?? '') == 'payment') {
    $paymentId = $notification['data']['id'];

    $url = "https://api.mercadopago.com/v1/payments/".$paymentId;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $payment = json_decode($response, true);

    if (isset($payment['status'])) {
        $status = $payment['status'];
        $external_reference = $payment['external_reference'] ?? '';
        $invoiceId = intval(str_ireplace('DF', '', $external_reference));

        // busca fatura
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        $invoiceTotal = $invoice->total ?? 0;

        $amountPaid = (float)($payment['transaction_amount'] ?? 0);
        $feeAmount = (float)($payment['fee_details'][0]['amount'] ?? 0);

        $logData = [
            'InvoiceId' => $invoiceId,
            'PaymentId' => $paymentId,
            'Status' => $status,
            'ValorPago' => $amountPaid,
            'ValorFatura' => $invoiceTotal,
            'RetornoMP' => $payment
        ];

        if ($status == 'approved') {
            if ($amountPaid < $invoiceTotal) {
                // pagamento parcial: registra apenas o log, adiciona o valor parcial
                logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'Pagamento parcial detectado');
                addInvoicePayment($invoiceId, $paymentId, $amountPaid, $feeAmount, PAYMENT_METHOD_MP_BOLETO);
            } else {
                addInvoicePayment($invoiceId, $paymentId, $amountPaid, $feeAmount, PAYMENT_METHOD_MP_BOLETO);
                logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'Boleto Pago');
            }
        } elseif (in_array($status, ['cancelled', 'rejected'])) {
            logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'Pagamento cancelado/rejeitado');
        } else {
            logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'Status ignorado');
        }
    }

    http_response_code(200);
    echo 'OK';
} else {
    http_response_code(400);
    echo 'Notificação inválida';
}
