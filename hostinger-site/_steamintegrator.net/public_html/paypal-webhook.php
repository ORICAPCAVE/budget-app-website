<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/budget-private/config.php';

function webhookLog(string $message): void
{
    file_put_contents(
        dirname(__DIR__) . '/budget-private/paypal-webhook-test.log',
        gmdate('Y-m-d H:i:s') . ' UTC - ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

header('Content-Type: text/plain; charset=utf-8');

/*
 * PayPal API base URL.
 */
$paypalBaseUrl = PAYPAL_SANDBOX
    ? 'https://api-m.sandbox.paypal.com'
    : 'https://api-m.paypal.com';


/*
 * Read the webhook exactly as PayPal sent it.
 */
$rawBody = file_get_contents('php://input');

$method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$bodyLength = ($rawBody === false) ? -1 : strlen($rawBody);

webhookLog(
    'request received: method=' . $method .
    ', body_length=' . $bodyLength
);

if ($rawBody === false || $rawBody === '') {

    webhookLog('missing webhook body');

    http_response_code(400);
    exit('Missing webhook body.');
}

$event = json_decode($rawBody, true);

if (!is_array($event)) {
    http_response_code(400);
    exit('Invalid JSON.');
}

webhookLog(
    'event_type = ' . ($event['event_type'] ?? 'MISSING')
);
webhookLog(
    'event_id = ' . ($event['id'] ?? 'MISSING')
);
/*
 * Ignore the known PayPal Webhook Simulator test event.
 * This prevents PayPal from continuing to retry it.
 */
if (
    ($event['id'] ?? '') ===
    'WH-58D329510W468432D-8HN650336L201105X'
) {
    webhookLog('known simulator event ignored');

    http_response_code(200);
    exit('Simulator event ignored.');
}

/*
 * Read PayPal verification headers.
 */
$transmissionId =
    $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '';

$transmissionTime =
    $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '';

$transmissionSig =
    $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '';

$certUrl =
    $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '';

$authAlgo =
    $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '';
    
    webhookLog(
    'PayPal headers: ' .
    'transmission_id=' . $transmissionId .
    ', transmission_time=' . $transmissionTime .
    ', auth_algo=' . $authAlgo .
    ', cert_url=' . $certUrl .
    ', transmission_sig_length=' . strlen($transmissionSig) .
    ', webhook_id=' . PAYPAL_WEBHOOK_ID
);


if (
    $transmissionId === '' ||
    $transmissionTime === '' ||
    $transmissionSig === '' ||
    $certUrl === '' ||
    $authAlgo === ''
) {
    webhookLog('missing PayPal verification headers');

    http_response_code(400);
    exit('Missing PayPal verification headers.');
}


/*
 * Obtain an OAuth access token from PayPal.
 */
$ch = curl_init(
    $paypalBaseUrl . '/v1/oauth2/token'
);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_USERPWD =>
        PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Accept-Language: en_US',
        'Content-Type: application/x-www-form-urlencoded'
    ],
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials'
]);

$oauthResponse = curl_exec($ch);
$oauthHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if (
    $oauthResponse === false ||
    $oauthHttpCode < 200 ||
    $oauthHttpCode >= 300
) {
    webhookLog(
        'PayPal OAuth failed, HTTP=' . $oauthHttpCode
    );

    http_response_code(500);
    exit('Unable to authenticate with PayPal.');
}

$oauthData = json_decode($oauthResponse, true);

$accessToken = $oauthData['access_token'] ?? '';

if ($accessToken === '') {
    webhookLog('PayPal access token missing');

    http_response_code(500);
    exit('PayPal access token missing.');
}


/*
 * Ask PayPal to verify the webhook signature.
 */
$verificationPayload = [
    'auth_algo' => $authAlgo,
    'cert_url' => $certUrl,
    'transmission_id' => $transmissionId,
    'transmission_sig' => $transmissionSig,
    'transmission_time' => $transmissionTime,
    'webhook_id' => PAYPAL_WEBHOOK_ID,
    'webhook_event' => $event
];

$ch = curl_init(
    $paypalBaseUrl .
    '/v1/notifications/verify-webhook-signature'
);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS =>
        json_encode(
            $verificationPayload,
            JSON_UNESCAPED_SLASHES
        )
]);

$verifyResponse = curl_exec($ch);
$verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if (
    $verifyResponse === false ||
    $verifyHttpCode < 200 ||
    $verifyHttpCode >= 300
) {
    webhookLog(
        'webhook verification request failed, HTTP=' .
        $verifyHttpCode
    );

    http_response_code(400);
    exit('Webhook verification request failed.');
}
webhookLog(
    'raw verification response = ' .
    var_export($verifyResponse, true)
);
webhookLog(
    'json decode error = ' .
    json_last_error_msg()
);

$verifyData = json_decode($verifyResponse, true);
webhookLog(
    'verification response = ' .
    json_encode($verifyData)
);

if (
    ($verifyData['verification_status'] ?? '')
    !== 'SUCCESS'
) {
    webhookLog('webhook signature verification FAILED');

    http_response_code(400);
    exit('Webhook signature verification failed.');
}

webhookLog('signature verification SUCCESS');


/*
 * We only fulfill completed captures.
 */
if (
    ($event['event_type'] ?? '')
    !== 'PAYMENT.CAPTURE.COMPLETED'
) {
    webhookLog(
        'ignored event: ' .
        ($event['event_type'] ?? 'UNKNOWN')
    );

    http_response_code(200);
    exit('Ignored event.');
}


/*
 * Extract payment details.
 */
$resource = $event['resource'] ?? [];

$transactionId =
    $resource['id'] ?? '';

$amount =
    $resource['amount']['value'] ?? '';

$currency =
    $resource['amount']['currency_code'] ?? '';

$status =
    $resource['status'] ?? '';

$payerEmail =
    $resource['payer']['email_address']
    ?? null;

webhookLog(
    'payment details: transaction=' . $transactionId .
    ', status=' . $status .
    ', amount=' . $amount .
    ', currency=' . $currency
);


/*
 * Verify the payment itself.
 */
if (
    $transactionId === '' ||
    $status !== 'COMPLETED' ||
    $amount !== '9.99' ||
    $currency !== 'USD'
) {
    webhookLog(
        'payment NOT eligible: status=' . $status .
        ', amount=' . $amount .
        ', currency=' . $currency
    );

    http_response_code(200);
    exit('Payment not eligible for download.');
}


/*
 * Create a secure download token.
 */
$downloadToken =
    bin2hex(random_bytes(32));

$expiresAt =
    (new DateTimeImmutable(
        'now',
        new DateTimeZone('UTC')
    ))
    ->modify(
        '+' . DOWNLOAD_TOKEN_HOURS . ' hours'
    )
    ->format('Y-m-d H:i:s');

webhookLog(
    'payment eligible - attempting database insert'
);


/*
 * Store purchase.
 */
try {

    $pdo = db();

    $sql = "
        INSERT INTO budget_downloads (
            paypal_transaction_id,
            payer_email,
            download_token,
            expires_at,
            download_count,
            max_downloads
        )
        VALUES (
            :transaction_id,
            :payer_email,
            :download_token,
            :expires_at,
            0,
            :max_downloads
        )
        ON DUPLICATE KEY UPDATE
            paypal_transaction_id =
                paypal_transaction_id
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':transaction_id' => $transactionId,
        ':payer_email' => $payerEmail,
        ':download_token' => $downloadToken,
        ':expires_at' => $expiresAt,
        ':max_downloads' => MAX_DOWNLOADS
    ]);

    webhookLog(
        'database insert completed, transaction=' .
        $transactionId
    );

} catch (Throwable $e) {

    webhookLog(
        'DATABASE ERROR: ' . $e->getMessage()
    );

    error_log(
        'Budget App PayPal webhook database error: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Database processing failed.');
}


/*
 * PayPal expects a successful 2xx response.
 */
webhookLog('webhook processed successfully');

http_response_code(200);
echo 'Webhook processed.';