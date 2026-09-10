<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/budget-private/config.php';

$token = $_GET['token'] ?? '';
$platform = $_GET['platform'] ?? '';

if ($token === '') {
    http_response_code(400);
    exit('Missing download token.');
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        id,
        download_token,
        expires_at,
        download_count,
        max_downloads
     FROM budget_downloads
     WHERE download_token = :token
     LIMIT 1'
);

$stmt->execute([
    ':token' => $token
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row === false) {
    http_response_code(404);
    exit('Invalid download link.');
}

/*
 * Check expiration.
 */
$expiresAt = new DateTimeImmutable(
    $row['expires_at'],
    new DateTimeZone('UTC')
);

$now = new DateTimeImmutable(
    'now',
    new DateTimeZone('UTC')
);

if ($now > $expiresAt) {
    http_response_code(403);
    exit('This download link has expired.');
}

/*
 * Check download limit.
 */
$downloadCount = (int)$row['download_count'];
$maxDownloads = (int)$row['max_downloads'];

if ($downloadCount >= $maxDownloads) {
    http_response_code(403);
    exit('This download link has reached its download limit.');
}

/*
 * If no platform was selected yet,
 * show the customer the choices.
 */
if ($platform === '') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width,
                       initial-scale=1.0">

        <title>
            Download Budget App
        </title>
    </head>

    <body>

    <h1>Download Budget App</h1>

    <p>
        Choose your operating system.
    </p>

    <p>
        Downloads used:
        <?php echo $downloadCount; ?>
        of
        <?php echo $maxDownloads; ?>
    </p>

    <p>
        <a href="<?php
            echo htmlspecialchars(
                'download.php?token=' .
                urlencode($token) .
                '&platform=mac',
                ENT_QUOTES,
                'UTF-8'
            );
        ?>">
            Download for Mac
        </a>
    </p>

    <p>
        <a href="<?php
            echo htmlspecialchars(
                'download.php?token=' .
                urlencode($token) .
                '&platform=windows',
                ENT_QUOTES,
                'UTF-8'
            );
        ?>">
            Download for Windows
        </a>
    </p>

    </body>
    </html>

    <?php
    exit;
}

/*
 * Select installer.
 */
if ($platform === 'mac') {

    $fileName = 'Budget.App-1.0.6.dmg';

} elseif ($platform === 'windows') {

    $fileName = 'Budget.App-1.0.6.exe';

} else {

    http_response_code(400);
    exit('Invalid platform.');
}

/*
 * Installer files live in the private
 * downloads directory configured in config.php.
 */
$filePath =
    rtrim(DOWNLOAD_DIR, DIRECTORY_SEPARATOR) .
    DIRECTORY_SEPARATOR .
    $fileName;

if (!is_file($filePath)) {
    http_response_code(404);
    exit('Download file not found.');
}

/*
 * Increment download count.
 */
$update = $pdo->prepare(
    'UPDATE budget_downloads
     SET download_count = download_count + 1
     WHERE id = :id
       AND download_count < max_downloads'
);

$update->execute([
    ':id' => $row['id']
]);

if ($update->rowCount() !== 1) {
    http_response_code(403);
    exit('Download limit reached.');
}

/*
 * Send installer.
 */
header('Content-Type: application/octet-stream');

header(
    'Content-Disposition: attachment; filename="' .
    basename($fileName) .
    '"'
);

header(
    'Content-Length: ' .
    filesize($filePath)
);

header('Cache-Control: no-store');

readfile($filePath);

exit;