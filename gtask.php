<?php
/**
 * gtask.php
 * Принимает данные из расширения Chrome и создаёт задачу в Google Tasks.
 * PHP 8+, cURL, OAuth 2.0 (Client ID/Secret от Google Cloud Console).
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// === НАСТРОЙКИ ===
$clientId     = 'YOUR_GOOGLE_CLIENT_ID';
$clientSecret = 'YOUR_GOOGLE_CLIENT_SECRET';
$redirectUri  = 'https://musthaveapp.na4u.ru/gtask.php';
$scope        = 'https://www.googleapis.com/auth/tasks';
$tokenFile    = __DIR__ . '/gtoken.json';

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===
function httpPost(string $url, array $data, string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $response];
}

function httpGet(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $response];
}

// === OAuth 2.0 ===
session_start();

function saveToken(array $token, string $file)
{
    file_put_contents($file, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function loadToken(string $file): ?array
{
    if (!file_exists($file)) return null;
    $token = json_decode(file_get_contents($file), true);
    if (!$token || !isset($token['access_token'])) return null;
    return $token;
}

// === Авторизация ===
$token = loadToken($tokenFile);

if (isset($_GET['code'])) {
    // Обмен кода на токен
    $postData = [
        'code' => $_GET['code'],
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);

    if (!empty($data['access_token'])) {
        saveToken($data, $tokenFile);
        header("Location: {$redirectUri}");
        exit;
    } else {
        echo "Ошибка авторизации: " . htmlspecialchars($response);
        exit;
    }
}

if (!$token) {
    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => $scope,
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    echo "<h3>Подключение Google Tasks</h3>
          <a href='$authUrl'>Авторизоваться через Google</a>";
    exit;
}

// === Проверяем токен и при необходимости обновляем ===
if (isset($token['expires_in'], $token['created']) && time() - $token['created'] >= $token['expires_in'] - 60) {
    if (isset($token['refresh_token'])) {
        $refreshData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $token['refresh_token'],
            'grant_type' => 'refresh_token'
        ];
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($refreshData),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $newToken = json_decode($response, true);
        if (isset($newToken['access_token'])) {
            $token['access_token'] = $newToken['access_token'];
            $token['created'] = time();
            saveToken($token, $tokenFile);
        } else {
            unlink($tokenFile);
            header("Location: {$redirectUri}");
            exit;
        }
    }
}

// === Создание задачи ===
$title = $_GET['title'] ?? 'Без названия';
$url   = $_GET['url'] ?? '';
$sel   = $_GET['selection'] ?? '';

$notes = trim(($sel ? $sel . "\n\n" : '') . $url);

$taskData = [
    'title' => $title,
    'notes' => $notes
];

$response = httpPost('https://tasks.googleapis.com/tasks/v1/lists/@default/tasks', $taskData, $token['access_token']);
$data = json_decode($response['body'], true);

header('Content-Type: text/html; charset=utf-8');

if (isset($data['id'])) {
    echo "<script>
      try {
        if (window.opener) {
          window.opener.postMessage('task_created', 'https://musthaveapp.na4u.ru');
        }
      } catch(e) {}
      document.write('<h3 style=\"font-family:sans-serif;color:green;\">✅ Задача создана</h3>');
      setTimeout(() => window.close(), 1200);
    </script>";
} else {
    $msg = htmlspecialchars($response['body']);
    echo "<script>
      try {
        if (window.opener) {
          window.opener.postMessage({type:'task_error', message:`$msg`}, 'https://musthaveapp.na4u.ru');
        }
      } catch(e) {}
      document.write('<h3 style=\"font-family:sans-serif;color:red;\">❌ Ошибка при создании задачи</h3>');
      document.write('<pre>$msg</pre>');
    </script>";
}
