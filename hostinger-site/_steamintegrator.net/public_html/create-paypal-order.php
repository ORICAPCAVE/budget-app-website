<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/budget-private/config.php';

header('Content-Type: text/html; charset=utf-8');

$paypalBaseUrl = PAYPAL_SANDBOX
    ? 'https://api-m.sandbox.paypal.com'
    : 'https://api-m.paypal.com';

/*
 * 1. Get OAuth access token
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

if ($oauthResponse === false) {
    $error = curl_error($ch);
    curl_close($ch);

    exit(
        'OAuth request failed: ' .
        htmlspecialchars($error)
    );
}

curl_close($ch);

if ($oauthHttpCode < 200 || $oauthHttpCode >= 300) {

    echo '<h2>PayPal OAuth failed</h2>';

    echo '<p>HTTP status: ' .
        htmlspecialchars((string)$oauthHttpCode) .
        '</p>';

    echo '<pre>' .
        htmlspecialchars($oauthResponse) .
        '</pre>';

    exit;
}

$oauthData = json_decode($oauthResponse, true);

$accessToken = $oauthData['access_token'] ?? '';
echo '<h3>PayPal OAuth scopes:</h3>';
echo '<pre>';
echo htmlspecialchars($oauthData['scope'] ?? 'NO SCOPE RETURNED');
echo '</pre>';

if ($accessToken === '') {
    exit('PayPal access token was not returned.');
}

/*
 * 2. Build the Budget App order
 */
$requestData = [
    'intent' => 'CAPTURE',

    'purchase_units' => [
        [
            'description' =>
                'Budget App for Mac & Windows',

            'amount' => [
                'currency_code' => 'USD',
                'value' => '9.99'
            ]
        ]
    ],

    'application_context' => [
        'return_url' =>
            'https://steamintegrator.net/thank-you.php',

        'cancel_url' =>
            'https://steamintegrator.net/',

        'user_action' => 'PAY_NOW'
    ]
];


/*
 * PayPal requires a unique request ID.
 */
$requestId = bin2hex(random_bytes(16));


/*
 * 3. Create the PayPal order
 */
$ch = curl_init(
    $paypalBaseUrl .
    '/v2/checkout/orders'
);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,

    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json',
        'PayPal-Request-Id: ' . $requestId
    ],

    CURLOPT_POSTFIELDS =>
        json_encode(
            $requestData,
            JSON_UNESCAPED_SLASHES
        )
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);

    exit(
        'Order request failed: ' .
        htmlspecialchars($error)
    );
}

curl_close($ch);

$data = json_decode($response, true);


/*
 * 4. Find the PayPal approval URL
 */
$approvalUrl = '';

if (isset($data['links'])) {

    foreach ($data['links'] as $link) {

        if (($link['rel'] ?? '') === 'approve') {

            $approvalUrl = $link['href'] ?? '';
            break;
        }
    }
}


/*
 * 5. Display result
 */
if (
    ($httpCode === 200 || $httpCode === 201) &&
    $approvalUrl !== ''
) {

  echo '<h2>Budget App Order Created</h2>';

    echo '<p>';

    echo '<a href="' .
        htmlspecialchars($approvalUrl) .
        '" target="_blank">';

   echo 'Continue to PayPal - $9.99';

    echo '</a>';

    echo '</p>';

} else {

   echo '<h2>Unable to create PayPal order</h2>';

    echo '<p>HTTP status: ' .
        htmlspecialchars((string)$httpCode) .
        '</p>';

    echo '<pre>' .
        htmlspecialchars($response) .
        '</pre>';
}