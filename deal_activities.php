<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$logFile = __DIR__ . '/webhook_debug.log'; // файл с логами можете заменить на ваш или оставить

date_default_timezone_set('UTC');
$timestamp = date('Y-m-d H:i:s');

$data = $_POST;

// Логируем входящие данные POST
file_put_contents($logFile, "[$timestamp] Данные POST: " . json_encode($data) . "\n", FILE_APPEND);

// Проверяем, что событие - ONCRMACTIVITYADD
if (!isset($data['event']) || $data['event'] !== 'ONCRMACTIVITYADD') {
    die(json_encode(['status' => 'error', 'message' => 'Некорректное событие']));
}

// Получаем ID активности
$activityId = $data['data']['FIELDS']['ID'] ?? null;
if (!$activityId) {
    file_put_contents($logFile, "[$timestamp] Ошибка: нет ID активности в запросе.\n", FILE_APPEND);
    die(json_encode(['status' => 'error', 'message' => 'Нет ID активности']));
}

// Подготовка URL API Bitrix24
$bitrix_domain = $_POST['auth']['domain'] ?? getenv('BITRIX_DOMAIN') ?? 'testgg.bitrix24.kz';
$webhook_url_get = "https://sillan-masterok.kz/rest/153/o81z3shqjmou1flv/crm.activity.get.json"; // Замените на ваш вебхук с методом crm.activity.get
$webhook_url_delete = "https://sillan-masterok.kz/rest/153/o81z3shqjmou1flv/crm.activity.delete.json"; // Замените на ваш вебхук с методом crm.activity.delete

// 🔹 Запрашиваем детали активности
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhook_url_get . "?ID=" . $activityId);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

// Логируем ответ от Битрикс
file_put_contents($logFile, "[$timestamp] Ответ от Bitrix24 на GET: " . $response . "\n", FILE_APPEND);

$activityData = json_decode($response, true);
if (!$activityData || !isset($activityData['result'])) {
    file_put_contents($logFile, "[$timestamp] Ошибка при получении данных активности.\n", FILE_APPEND);
    die(json_encode(['status' => 'error', 'message' => 'Ошибка при получении данных активности']));
}

$activity = $activityData['result'];
$providerTypeId = $activity['PROVIDER_ID'] ?? '';

// Логируем полученные параметры активности
file_put_contents($logFile, "[$timestamp] Данные активности ID $activityId: " . json_encode($activity) . "\n", FILE_APPEND);

if ($providerTypeId === 'IMOPENLINES_SESSION' && !in_array($activity['PROVIDER_TYPE_ID'], ['2' , '3' , '5' , '6' , '7' , '13' , '27' , '42' , '43'])) {
    // Удаление активности
    file_put_contents($logFile, "[$timestamp] Активность с ID $activityId определена как 'Чат открытой линии'. Готовимся к удалению.\n", FILE_APPEND);

    // Удаляем активность
    $deleteData = ['ID' => $activityId];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhook_url_delete);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($deleteData));

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        file_put_contents($logFile, "[$timestamp] Ошибка cURL: $error\n", FILE_APPEND);
        exit(json_encode(['status' => 'error', 'message' => 'Ошибка при отправке запроса'])); 
    }
    curl_close($ch);

    // Логируем результат удаления
    file_put_contents($logFile, "[$timestamp] Ответ от Bitrix24 на DELETE: " . $response . "\n", FILE_APPEND);

    $responseData = json_decode($response, true);
    if (!empty($responseData['result'])) {
        file_put_contents($logFile, "[$timestamp] Активность $activityId успешно удалена.\n", FILE_APPEND);
        exit(json_encode(['status' => 'ok', 'message' => 'Активность удалена']));
    } else {
        file_put_contents($logFile, "[$timestamp] Ошибка при удалении активности: " . json_encode($responseData) . "\n", FILE_APPEND);
        exit(json_encode(['status' => 'error', 'message' => 'Ошибка при удалении активности']));
    }
} else {
    file_put_contents($logFile, "[$timestamp] Активность с ID $activityId не соответствует условиям для удаления.\n", FILE_APPEND);
    exit(json_encode(['status' => 'ok', 'message' => 'Активность не требует удаления']));
}


?>