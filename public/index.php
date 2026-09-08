<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Plaud\Config;
use Plaud\DTO\Recording;
use Plaud\DTO\RecordingDetail;
use Plaud\Exceptions\PlaudException;
use Plaud\PlaudClient;
use Plaud\Storage\FileTokenStorage;
use PlaudExample\RecordingContent;

session_start();

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\\\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

$appName = getenv('APP_NAME') ?: 'Plaud Workspace';
$region = getenv('PLAUD_REGION') ?: 'us';
$tokenStorage = new FileTokenStorage($root . '/storage/plaud-token.json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$recordingId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$error = null;
$recordings = [];
$detail = null;

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function createClient(FileTokenStorage $storage, string $region): PlaudClient
{
    $email = $_SESSION['plaud_email'] ?? null;
    $password = $_SESSION['plaud_password'] ?? null;
    $config = new Config(region: $region, email: $email, password: $password);

    return PlaudClient::create($config, $storage);
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requireApiConnection(): void
{
    if (empty($_SESSION['plaud_connected'])) {
        jsonResponse(['message' => 'Plaud connection required.'], 401);
    }
}

function resolveTranscript(RecordingDetail $recording): string
{
    return RecordingContent::resolveTranscript($recording);
}

function resolveSummary(RecordingDetail $recording): ?string
{
    return RecordingContent::resolveSummary($recording);
}

function fetchSummaryContent(PlaudClient $client, RecordingDetail $recording): ?string
{
    foreach (RecordingContent::summaryLinks($recording) as $url) {
        try {
            $response = $client->getHttpClient()->request('GET', $url);
            if (!$response->isOk()) {
                continue;
            }

            $body = $response->getBody();
            $decoded = str_starts_with($body, "\x1f\x8b") ? gzdecode($body) : $body;
            if (is_string($decoded) && trim($decoded) !== '') {
                return trim($decoded);
            }
        } catch (Throwable) {
            continue;
        }
    }

    return null;
}

if (str_starts_with($path, '/api/')) {
    try {
        if ($path === '/api/connect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($email === '' || $password === '') {
                jsonResponse(['message' => 'Email and password are required.'], 422);
            }

            $_SESSION['plaud_email'] = $email;
            $_SESSION['plaud_password'] = $password;
            createClient($tokenStorage, $region)->listRecordings();
            $_SESSION['plaud_connected'] = true;
            jsonResponse(['connected' => true]);
        }

        if ($path === '/api/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $tokenStorage->clearToken();
            $_SESSION = [];
            session_destroy();
            jsonResponse(['connected' => false]);
        }

        requireApiConnection();
        $client = createClient($tokenStorage, $region);

        if ($path === '/api/recordings' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $items = array_map(static fn (Recording $recording): array => [
                'id' => $recording->id,
                'filename' => $recording->filename,
                'date' => $recording->getFormattedStartDate('M j, Y / H:i'),
                'durationMinutes' => $recording->getDurationMinutes(),
            ], $client->listRecordings());
            jsonResponse(['recordings' => $items]);
        }

        if (preg_match('#^/api/recordings/([^/]+)/audio-url$#', $path, $matches) === 1 && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $audioUrl = $client->getMp3Url(urldecode($matches[1]));
            if ($audioUrl === null) {
                jsonResponse(['message' => 'Audio URL is not available.'], 404);
            }

            jsonResponse(['audioUrl' => $audioUrl]);
        }

        if (preg_match('#^/api/recordings/([^/]+)$#', $path, $matches) === 1 && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $recording = $client->getRecording(urldecode($matches[1]));
            $summary = resolveSummary($recording) ?? fetchSummaryContent($client, $recording);
            jsonResponse(['recording' => [
                'id' => $recording->id,
                'filename' => $recording->filename,
                'date' => $recording->getFormattedStartDate('M j, Y / H:i'),
                'durationMinutes' => $recording->getDurationMinutes(),
                'transcript' => resolveTranscript($recording),
                'summary' => $summary,
            ]]);
        }

        jsonResponse(['message' => 'API route not found.'], 404);
    } catch (Throwable $exception) {
        if ($exception instanceof \Plaud\Exceptions\AuthenticationException) {
            unset($_SESSION['plaud_connected']);
        }
        if ($path === '/api/connect') {
            unset($_SESSION['plaud_email'], $_SESSION['plaud_password'], $_SESSION['plaud_connected']);
        }
        $status = $exception instanceof \Plaud\Exceptions\AuthenticationException ? 401 : 500;
        jsonResponse(['message' => $exception instanceof PlaudException ? $exception->getMessage() : 'Unable to load Plaud data.'], $status);
    }
}

$frontendIndex = $root . '/public/app/index.html';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($path === '/' || $path === '/index.html') && is_file($frontendIndex)) {
    readfile($frontendIndex);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/connect') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $_SESSION['plaud_email'] = $email;
        $_SESSION['plaud_password'] = $password;
        try {
            createClient($tokenStorage, $region)->listRecordings();
            $_SESSION['plaud_connected'] = true;
            header('Location: /');
            exit;
        } catch (Throwable $exception) {
            unset($_SESSION['plaud_connected'], $_SESSION['plaud_email'], $_SESSION['plaud_password']);
            $error = $exception instanceof PlaudException ? $exception->getMessage() : 'Unable to connect to Plaud.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/disconnect') {
    $tokenStorage->clearToken();
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

$connected = !empty($_SESSION['plaud_connected']);

if ($connected) {
    try {
        $client = createClient($tokenStorage, $region);
        if ($recordingId !== '') {
            $detail = $client->getRecording($recordingId);
        } else {
            $recordings = $client->listRecordings();
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof PlaudException ? $exception->getMessage() : 'Unable to load Plaud data.';
        if ($exception instanceof \Plaud\Exceptions\AuthenticationException) {
            $connected = false;
            unset($_SESSION['plaud_connected']);
        }
    }
}

$formatDate = static fn (Recording $recording): string => $recording->getFormattedStartDate('M j, Y / H:i') ?: 'Undated recording';
$pageTitle = $detail instanceof RecordingDetail ? $detail->filename : 'Recordings';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($pageTitle . ' · ' . $appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="/">
                <span class="brand-mark">P</span>
                <span><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <span class="connection-status"><span class="status-dot <?= $connected ? 'is-connected' : '' ?>"></span><?= $connected ? 'Connected' : 'Not connected' ?></span>
        </header>

        <?php if ($error !== null): ?>
            <div class="alert" role="alert"><?= escape($error) ?></div>
        <?php endif; ?>

        <?php if (!$connected): ?>
            <section class="hero">
                <p class="eyebrow">Plaud integration workspace</p>
                <h1>Your conversations,<br><em>ready to revisit.</em></h1>
                <p class="hero-copy">Connect a Plaud account to explore recordings, transcripts, and summaries in one focused workspace.</p>
            </section>

            <section class="connect-panel" aria-labelledby="connect-title">
                <div>
                    <p class="panel-kicker">Temporary connection</p>
                    <h2 id="connect-title">Connect your Plaud account</h2>
                    <p class="panel-copy">Credentials are used only for the current session. OAuth will replace this flow after private-beta access is available.</p>
                </div>
                <form class="connect-form" action="/connect" method="post">
                    <label><span>Email address</span><input type="email" name="email" placeholder="you@example.com" autocomplete="email" required></label>
                    <label><span>Password</span><input type="password" name="password" placeholder="Plaud password" autocomplete="current-password" required></label>
                    <button type="submit">Connect account <span aria-hidden="true">&rarr;</span></button>
                    <small>Region: <?= escape($region) ?></small>
                </form>
            </section>
        <?php elseif ($detail instanceof RecordingDetail): ?>
            <section class="detail-heading">
                <a class="back-link" href="/">&larr; All recordings</a>
                <p class="eyebrow">Recording detail</p>
                <h1><?= escape($detail->filename) ?></h1>
                <p class="detail-meta"><?= escape($formatDate($detail)) ?> &middot; <?= $detail->getDurationMinutes() ?> min</p>
            </section>

            <section class="content-grid" aria-label="Recording content">
                <article class="content-panel">
                    <div class="section-heading"><span class="panel-kicker">Transcript</span><span class="content-state"><?= $detail->hasTranscript() ? 'Available' : 'Not available' ?></span></div>
                    <div class="rich-text"><?= $detail->hasTranscript() ? nl2br(escape($detail->transcript)) : '<p>No transcript is available for this recording.</p>' ?></div>
                </article>
                <article class="content-panel summary-panel">
                    <div class="section-heading"><span class="panel-kicker">Summary</span><span class="content-state"><?= $detail->summary ? 'Available' : 'Not available' ?></span></div>
                    <div class="rich-text"><?= $detail->summary ? nl2br(escape($detail->summary)) : '<p>No summary is available for this recording.</p>' ?></div>
                </article>
            </section>
        <?php else: ?>
            <section class="workspace-heading">
                <div><p class="eyebrow">Plaud workspace</p><h1>Recordings</h1><p class="hero-copy">A focused view of your recent conversations.</p></div>
                <form action="/disconnect" method="post"><button class="secondary-button" type="submit">Disconnect</button></form>
            </section>
            <section class="recording-list" aria-label="Recordings">
                <?php if ($recordings === []): ?>
                    <div class="empty-state"><h2>No recordings yet</h2><p>Your active Plaud recordings will appear here.</p></div>
                <?php else: ?>
                    <?php foreach ($recordings as $recording): ?>
                        <a class="recording-row" href="/?id=<?= urlencode($recording->id) ?>">
                            <span class="recording-index">&#8599;</span><span class="recording-title"><strong><?= escape($recording->filename) ?></strong><small><?= escape($formatDate($recording)) ?></small></span><span class="recording-duration"><?= $recording->getDurationMinutes() ?> min</span><span aria-hidden="true">&rarr;</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>