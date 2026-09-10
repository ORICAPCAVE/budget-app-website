<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/budget-private/config.php';

$paypalBaseUrl = PAYPAL_SANDBOX
    ? 'https://api-m.sandbox.paypal.com'
    : 'https://api-m.paypal.com';

$message = '';
$success = false;
$downloadUrl = '';
$transactionId = '';

/*
 * PayPal returns the approved order ID as "token".
 */
$orderId = $_GET['token'] ?? '';

if ($orderId === '') {

    $message =
        'PayPal did not return an order ID. ' .
        'The payment could not be completed.';

} else {

    /*
     * 1. Get OAuth access token.
     */
    $ch = curl_init(
        $paypalBaseUrl . '/v1/oauth2/token'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,

        CURLOPT_USERPWD =>
            PAYPAL_CLIENT_ID . ':' .
            PAYPAL_CLIENT_SECRET,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: en_US',
            'Content-Type: application/x-www-form-urlencoded'
        ],

        CURLOPT_POSTFIELDS =>
            'grant_type=client_credentials'
    ]);

    $oauthResponse = curl_exec($ch);
    $oauthHttpCode =
        curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if (
        $oauthResponse === false ||
        $oauthHttpCode < 200 ||
        $oauthHttpCode >= 300
    ) {

        $message =
            'We could not confirm your payment with PayPal.';

    } else {

        $oauthData =
            json_decode($oauthResponse, true);

        $accessToken =
            $oauthData['access_token'] ?? '';

        if ($accessToken === '') {

            $message =
                'PayPal authentication failed.';

        } else {

            /*
             * 2. Capture the approved order.
             */
            $ch = curl_init(
                $paypalBaseUrl .
                '/v2/checkout/orders/' .
                rawurlencode($orderId) .
                '/capture'
            );

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,

                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' .
                        $accessToken,
                    'Content-Type: application/json'
                ],

                CURLOPT_POSTFIELDS => '{}'
            ]);

            $captureResponse =
                curl_exec($ch);

            $captureHttpCode =
                curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );

            curl_close($ch);

            if (
                $captureResponse !== false &&
                $captureHttpCode >= 200 &&
                $captureHttpCode < 300
            ) {

                $captureData =
                    json_decode(
                        $captureResponse,
                        true
                    );

                if (
                    ($captureData['status'] ?? '')
                    === 'COMPLETED'
                ) {

                    $success = true;
                    $transactionId =
                          $captureData['purchase_units'][0]
                          ['payments']['captures'][0]['id']
                          ?? '';

                    $message =
    'Your Budget App purchase ' .
    'was completed successfully.';

if ($transactionId !== '') {

    /*
     * Give the webhook a few seconds
     * to insert the verified download row.
     */
    $pdo = db();

    for ($attempt = 0; $attempt < 5; $attempt++) {

        $stmt = $pdo->prepare(
            'SELECT download_token
             FROM budget_downloads
             WHERE paypal_transaction_id = :transaction_id
             LIMIT 1'
        );

        $stmt->execute([
            ':transaction_id' => $transactionId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {

            $downloadUrl =
                'download.php?token=' .
                urlencode($row['download_token']);

            break;
        }

        sleep(1);
    }
}

                } else {

                    $message =
                        'PayPal returned the order, ' .
                        'but the payment is not completed yet.';
                }

            } else {

                $message =
                    'We were unable to capture ' .
                    'the PayPal payment.';
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width,
                   initial-scale=1.0">

    <title>
        Thank You - Budget App
    </title>

</head>

<body>

<h1>
    <?php
    echo $success
        ? 'Thank You for Your Purchase!'
        : 'Payment Status';
    ?>
</h1>

<p>
    <?php
    echo htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    );
    ?>
</p>

<?php if ($success && $downloadUrl !== ''): ?>

<p>
    Your secure download is ready.
</p>

<p>
    <a href="<?php
        echo htmlspecialchars(
            $downloadUrl,
            ENT_QUOTES,
            'UTF-8'
        );
    ?>">
        Download Budget App
    </a>
</p>

<?php elseif ($success): ?>

<p>
    Your payment was completed successfully.
    Your secure download is still being prepared.
    Please refresh this page in a few seconds.
</p>

<?php endif; ?>

</body>

</html>