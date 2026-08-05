<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function buildS3Client(string $region, string $endpoint, string $accessKey, string $secretKey): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => $region,
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => $accessKey,
            'secret' => $secretKey,
        ],
    ]);
}

function failJson(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$endpoint = getenv('S3_ENDPOINT') ?: "https://s3n.erikserver.in";
$region = getenv('S3_REGION') ?: "ap-southeast-1";
// Read from the runtime environment, never hardcoded. This file is tracked in git AND
// sits in the docroot of s3.shiplore.in, so a literal key here is published twice over.
// The guards below already fail with exactly this message when they are unset, which is
// how this script was originally written before the literals were pasted in.
$accessKey = (string) (getenv('S3_ACCESS_KEY') ?: '');
$secretKey = (string) (getenv('S3_SECRET_KEY') ?: '');

$defaultBucket = "allwhitelabeldata";
$defaultKeyPrefix = 'metadata/hellotest.jpg';

$errorMessage = '';
$successMessage = '';
$normalResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_presigned') {
    $bucket = trim((string) ($_POST['bucket'] ?? ''));
    $key = ltrim(trim((string) ($_POST['key'] ?? '')), '/');
    $acl = trim((string) ($_POST['acl'] ?? 'private'));
    $expires = (int) ($_POST['expires'] ?? 900);

    if ($accessKey === '' || $secretKey === '') {
        failJson('Missing S3_ACCESS_KEY or S3_SECRET_KEY in runtime environment.', 500);
    }

    if ($bucket === '' || $key === '') {
        failJson('Bucket and key are required.');
    }

    if (! in_array($acl, ['private', 'public-read'], true)) {
        failJson('ACL must be private or public-read.');
    }

    if ($expires < 1 || $expires > 3600) {
        failJson('Expires must be between 1 and 3600 seconds.');
    }

    try {
        $client = buildS3Client($region, $endpoint, $accessKey, $secretKey);
        $command = $client->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key' => $key,
            'ACL' => $acl,
        ]);
        $presigned = $client->createPresignedRequest($command, '+' . $expires . ' seconds');

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'url' => (string) $presigned->getUri(),
            'headers' => [
                'x-amz-acl' => $acl,
            ],
            'bucket' => $bucket,
            'key' => $key,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (AwsException $e) {
        failJson('AWS SDK error: ' . $e->getAwsErrorMessage(), 400);
    } catch (Throwable $e) {
        failJson('Presign failed: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'init_multipart') {
    $bucket = trim((string) ($_POST['bucket'] ?? ''));
    $key = ltrim(trim((string) ($_POST['key'] ?? '')), '/');
    $contentType = trim((string) ($_POST['contentType'] ?? 'application/octet-stream'));

    if ($accessKey === '' || $secretKey === '') {
        failJson('Missing S3_ACCESS_KEY or S3_SECRET_KEY in runtime environment.', 500);
    }
    if ($bucket === '' || $key === '') {
        failJson('Bucket and key are required.');
    }

    try {
        $client = buildS3Client($region, $endpoint, $accessKey, $secretKey);
        $result = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'uploadId' => $result['UploadId'],
            'bucket' => $bucket,
            'key' => $key,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (AwsException $e) {
        failJson('CreateMultipartUpload failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()), 400);
    } catch (Throwable $e) {
        failJson('CreateMultipartUpload failed: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'presign_part') {
    $bucket = trim((string) ($_POST['bucket'] ?? ''));
    $key = ltrim(trim((string) ($_POST['key'] ?? '')), '/');
    $uploadId = trim((string) ($_POST['uploadId'] ?? ''));
    $partNumber = (int) ($_POST['partNumber'] ?? 0);
    $expires = (int) ($_POST['expires'] ?? 900);

    if ($accessKey === '' || $secretKey === '') {
        failJson('Missing S3_ACCESS_KEY or S3_SECRET_KEY in runtime environment.', 500);
    }
    if ($bucket === '' || $key === '' || $uploadId === '' || $partNumber < 1) {
        failJson('Bucket, key, uploadId and valid partNumber are required.');
    }

    try {
        $client = buildS3Client($region, $endpoint, $accessKey, $secretKey);
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);
        $presigned = $client->createPresignedRequest($command, '+' . $expires . ' seconds');

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'url' => (string) $presigned->getUri(),
            'partNumber' => $partNumber,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (AwsException $e) {
        failJson('PresignPart failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()), 400);
    } catch (Throwable $e) {
        failJson('PresignPart failed: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? $_GET['action'] ?? '') === 'complete_multipart')) {
    $input = json_decode(file_get_contents('php://input'), true);
    $bucket = trim((string) ($input['bucket'] ?? ''));
    $key = ltrim(trim((string) ($input['key'] ?? '')), '/');
    $uploadId = trim((string) ($input['uploadId'] ?? ''));
    $parts = $input['parts'] ?? [];

    if ($accessKey === '' || $secretKey === '') {
        failJson('Missing S3_ACCESS_KEY or S3_SECRET_KEY in runtime environment.', 500);
    }
    if ($bucket === '' || $key === '' || $uploadId === '' || ! is_array($parts) || $parts === []) {
        failJson('Bucket, key, uploadId and parts are required.');
    }

    $multipartParts = [];
    foreach ($parts as $p) {
        $multipartParts[] = [
            'PartNumber' => (int) ($p['partNumber'] ?? 0),
            'ETag' => (string) ($p['etag'] ?? ''),
        ];
    }

    try {
        $client = buildS3Client($region, $endpoint, $accessKey, $secretKey);
        $result = $client->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $multipartParts],
        ]);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'etag' => (string) ($result['ETag'] ?? ''),
            'bucket' => $bucket,
            'key' => $key,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (AwsException $e) {
        failJson('CompleteMultipartUpload failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()), 400);
    } catch (Throwable $e) {
        failJson('CompleteMultipartUpload failed: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'normal_upload') {
    $bucket = trim((string) ($_POST['bucket'] ?? ''));
    $acl = trim((string) ($_POST['acl'] ?? 'private'));
    $keyInput = trim((string) ($_POST['key'] ?? ''));

    if ($accessKey === '' || $secretKey === '') {
        $errorMessage = 'Missing S3_ACCESS_KEY or S3_SECRET_KEY in runtime environment.';
    } elseif ($bucket === '') {
        $errorMessage = 'Bucket is required.';
    } elseif (! in_array($acl, ['private', 'public-read'], true)) {
        $errorMessage = 'ACL must be private or public-read.';
    } elseif (! isset($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'File upload failed before PutObject request.';
    } else {
        $upload = $_FILES['file'];
        $originalName = (string) ($upload['name'] ?? 'upload.bin');
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName)) ?: 'upload.bin';
        $key = ltrim($keyInput !== '' ? $keyInput : $defaultKeyPrefix . bin2hex(random_bytes(8)) . '-' . $safeName, '/');
        $contentType = (string) ($upload['type'] ?? 'application/octet-stream');

        try {
            $client = buildS3Client($region, $endpoint, $accessKey, $secretKey);
            $result = $client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'ACL' => $acl,
                'Body' => fopen((string) $upload['tmp_name'], 'rb'),
                'ContentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
            ]);

            $normalResult = [
                'bucket' => $bucket,
                'key' => $key,
                'etag' => (string) ($result['ETag'] ?? ''),
            ];
            $successMessage = 'Normal PutObject upload succeeded.';
        } catch (AwsException $e) {
            $errorMessage = 'PutObject failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage());
        } catch (Throwable $e) {
            $errorMessage = 'PutObject failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>S3 Upload Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f5f7fb; color: #111827; }
        h1 { margin-top: 0; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .card { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 16px; }
        label { display: block; margin-top: 10px; font-size: 14px; font-weight: 600; }
        input, select, button, textarea { width: 100%; margin-top: 6px; padding: 9px; box-sizing: border-box; }
        button { cursor: pointer; font-weight: 600; }
        .ok { background: #ecfdf5; border: 1px solid #10b981; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .err { background: #fef2f2; border: 1px solid #ef4444; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        code { background: #f3f4f6; padding: 2px 4px; border-radius: 4px; }
        small { color: #374151; }
    </style>
</head>
<body>
    <h1>S3 Upload Test Page</h1>
    <?php //echo dirname(__DIR__) . '/vendor/autoload.php'; ?>
    <p>Endpoint: <code><?= h($endpoint) ?></code> | Region: <code><?= h($region) ?></code></p>
    <?php if ($accessKey === '' || $secretKey === ''): ?>
        <div class="err">Missing runtime credentials. Set <code>S3_ACCESS_KEY</code> and <code>S3_SECRET_KEY</code> in Nginx/PHP-FPM env.</div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
        <div class="ok"><?= h($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="err"><?= h($errorMessage) ?></div>
    <?php endif; ?>
    <?php if (is_array($normalResult)): ?>
        <div class="ok">Uploaded <code><?= h($normalResult['bucket'] . '/' . $normalResult['key']) ?></code> ETag: <code><?= h($normalResult['etag']) ?></code></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h2>Normal Upload (PutObject)</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="normal_upload">
                <label>Bucket</label>
                <input type="text" name="bucket" value="<?= h($defaultBucket) ?>" required>

                <label>Key (optional)</label>
                <input type="text" name="key" placeholder="<?= h($defaultKeyPrefix) ?>file.jpg">

                <label>ACL</label>
                <select name="acl">
                    <option value="private">private</option>
                    <option value="public-read">public-read</option>
                </select>

                <label>File</label>
                <input type="file" name="file" required>

                <button type="submit">Upload via PutObject</button>
            </form>
        </div>

        <div class="card">
            <h2>Presigned Upload (Browser PUT)</h2>
            <label>Bucket</label>
            <input type="text" id="pre_bucket" value="<?= h($defaultBucket) ?>">

            <label>Key</label>
            <input type="text" id="pre_key" placeholder="<?= h($defaultKeyPrefix) ?>file.jpg">

            <label>ACL</label>
            <select id="pre_acl">
                <option value="public-read">public-read</option>
                <option value="private">private</option>
            </select>

            <label>Expires (seconds)</label>
            <input type="number" id="pre_expires" value="900" min="1" max="3600">

            <label>File</label>
            <input type="file" id="pre_file">

            <button type="button" id="pre_upload_btn">Generate Presigned URL and Upload</button>
            <small>For this project, request must send header <code>x-amz-acl</code> matching the presigned ACL.</small>
            <label>Result</label>
            <textarea id="pre_result" rows="10" readonly></textarea>
        </div>

        <div class="card">
            <h2>Presigned Multipart Upload</h2>
            <small>Splits file into chunks, gets presigned URLs for each part, uploads directly from browser, then completes.</small>
            <label>Bucket</label>
            <input type="text" id="mp_bucket" value="<?= h($defaultBucket) ?>">

            <label>Key</label>
            <input type="text" id="mp_key" placeholder="<?= h($defaultKeyPrefix) ?>largefile.zip">

            <label>Chunk size (MB)</label>
            <input type="number" id="mp_chunk" value="5" min="5" max="100">

            <label>File</label>
            <input type="file" id="mp_file">

            <button type="button" id="mp_upload_btn">Start Presigned Multipart Upload</button>
            <label>Progress</label>
            <div style="background:#e5e7eb;border-radius:4px;overflow:hidden;margin-top:6px">
                <div id="mp_progress_bar" style="height:24px;background:#3b82f6;width:0%;transition:width .3s;text-align:center;color:#fff;font-size:12px;line-height:24px">0%</div>
            </div>
            <label>Result</label>
            <textarea id="mp_result" rows="10" readonly></textarea>
        </div>
    </div>

    <script>
        async function presignedUpload() {
            const resultBox = document.getElementById('pre_result');
            const fileInput = document.getElementById('pre_file');
            const bucket = document.getElementById('pre_bucket').value.trim();
            const key = document.getElementById('pre_key').value.trim();
            const acl = document.getElementById('pre_acl').value;
            const expires = document.getElementById('pre_expires').value;
            const file = fileInput.files[0];

            if (!bucket || !key || !file) {
                resultBox.value = 'Bucket, key and file are required.';
                return;
            }

            resultBox.value = 'Generating presigned URL...';
            const form = new URLSearchParams();
            form.append('action', 'create_presigned');
            form.append('bucket', bucket);
            form.append('key', key);
            form.append('acl', acl);
            form.append('expires', expires);

            const signResp = await fetch('s3test.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: form.toString()
            });
            const signData = await signResp.json();
            if (!signResp.ok || !signData.ok) {
                resultBox.value = 'Presign failed: ' + (signData.error || signResp.statusText);
                return;
            }

            resultBox.value = 'Presigned URL generated.\nUploading file...';
            const uploadResp = await fetch(signData.url, {
                method: 'PUT',
                headers: {
                    'Content-Type': file.type || 'application/octet-stream',
                    'x-amz-acl': signData.headers['x-amz-acl']
                },
                body: file
            });

            const responseText = await uploadResp.text();
            resultBox.value = [
                'Upload status: ' + uploadResp.status + ' ' + uploadResp.statusText,
                'Object: ' + signData.bucket + '/' + signData.key,
                'Response:',
                responseText || '(empty)'
            ].join('\n');
        }

        document.getElementById('pre_upload_btn').addEventListener('click', function () {
            presignedUpload().catch(function (error) {
                document.getElementById('pre_result').value = 'Upload failed: ' + error.message;
            });
        });

        async function presignedMultipartUpload() {
            const resultBox = document.getElementById('mp_result');
            const progressBar = document.getElementById('mp_progress_bar');
            const bucket = document.getElementById('mp_bucket').value.trim();
            const key = document.getElementById('mp_key').value.trim();
            const chunkMB = parseInt(document.getElementById('mp_chunk').value, 10) || 5;
            const file = document.getElementById('mp_file').files[0];

            if (!bucket || !key || !file) {
                resultBox.value = 'Bucket, key and file are required.';
                return;
            }

            const chunkSize = chunkMB * 1024 * 1024;
            const totalParts = Math.ceil(file.size / chunkSize);
            resultBox.value = 'File: ' + file.name + ' (' + file.size + ' bytes)\nParts: ' + totalParts + '\n\n';

            // Step 1: Initiate multipart upload
            resultBox.value += 'Step 1: Initiating multipart upload...\n';
            const initForm = new URLSearchParams();
            initForm.append('action', 'init_multipart');
            initForm.append('bucket', bucket);
            initForm.append('key', key);
            initForm.append('contentType', file.type || 'application/octet-stream');

            const initResp = await fetch('s3test.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: initForm.toString()
            });
            const initData = await initResp.json();
            if (!initResp.ok || !initData.ok) {
                resultBox.value += 'ERROR: ' + (initData.error || initResp.statusText) + '\n';
                return;
            }
            const uploadId = initData.uploadId;
            resultBox.value += 'UploadId: ' + uploadId + '\n\n';

            // Step 2: Upload each part using presigned URLs
            const completedParts = [];
            for (let i = 1; i <= totalParts; i++) {
                const start = (i - 1) * chunkSize;
                const end = Math.min(i * chunkSize, file.size);
                const chunk = file.slice(start, end);

                resultBox.value += 'Step 2.' + i + ': Getting presigned URL for part ' + i + '...\n';
                const partForm = new URLSearchParams();
                partForm.append('action', 'presign_part');
                partForm.append('bucket', bucket);
                partForm.append('key', key);
                partForm.append('uploadId', uploadId);
                partForm.append('partNumber', String(i));
                partForm.append('expires', '900');

                const partSignResp = await fetch('s3test.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: partForm.toString()
                });
                const partSignData = await partSignResp.json();
                if (!partSignResp.ok || !partSignData.ok) {
                    resultBox.value += 'ERROR signing part ' + i + ': ' + (partSignData.error || partSignResp.statusText) + '\n';
                    return;
                }

                resultBox.value += 'Uploading part ' + i + '/' + totalParts + ' (' + chunk.size + ' bytes)...\n';
                const uploadResp = await fetch(partSignData.url, {
                    method: 'PUT',
                    body: chunk
                });

                if (!uploadResp.ok) {
                    const errText = await uploadResp.text();
                    resultBox.value += 'ERROR uploading part ' + i + ': HTTP ' + uploadResp.status + ' ' + errText + '\n';
                    return;
                }

                const etag = uploadResp.headers.get('ETag') || '';
                completedParts.push({ partNumber: i, etag: etag });
                resultBox.value += 'Part ' + i + ' done. ETag: ' + etag + '\n';

                const pct = Math.round((i / totalParts) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
            }

            // Step 3: Complete multipart upload
            resultBox.value += '\nStep 3: Completing multipart upload...\n';
            const completeResp = await fetch('s3test.php?action=complete_multipart', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bucket: bucket,
                    key: key,
                    uploadId: uploadId,
                    parts: completedParts
                })
            });
            const completeData = await completeResp.json();
            if (!completeResp.ok || !completeData.ok) {
                resultBox.value += 'ERROR completing: ' + (completeData.error || completeResp.statusText) + '\n';
                return;
            }

            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            resultBox.value += '\n✅ Upload complete!\nBucket: ' + completeData.bucket + '\nKey: ' + completeData.key + '\nETag: ' + completeData.etag + '\n';
        }

        document.getElementById('mp_upload_btn').addEventListener('click', function () {
            presignedMultipartUpload().catch(function (error) {
                document.getElementById('mp_result').value = 'Upload failed: ' + error.message;
            });
        });
    </script>
</body>
</html>
