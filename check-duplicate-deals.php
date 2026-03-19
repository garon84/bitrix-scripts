<?php

$dealId = {{ID}}; // ID текущей сделки

// Вебхук Bitrix24
$webhookUrl = 'https://exemple.com/rest/1/rest/';

// Файл лога
$logFile = __DIR__ . '/webhook-debug.log';

// Логирование
function debugLog($message, $data = null)
{
    global $logFile;

    $timestamp = date('Y-m-d H:i:s');
    $entry     = "[{$timestamp}] {$message}";

    if ($data !== null) {
        $entry .= "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    file_put_contents($logFile, $entry . "\n\n", FILE_APPEND);
}

// Функция вызова REST API с дебаг-логированием
function bitrixRestCall($url, $params)
{
    debugLog("REQUEST → {$url}", $params);

    $options = [
        'http' => [
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($params),
            'timeout' => 20,
        ]
    ];

    $context = stream_context_create($options);
    $result  = file_get_contents($url, false, $context);

    if ($result === false) {
        debugLog("ERROR → Ошибка соединения с REST API: {$url}");
        die("Ошибка соединения с REST API");
    }

    $decoded = json_decode($result, true);

    debugLog("RESPONSE ← {$url}", $decoded);

    return $decoded;
}


debugLog("=== Старт скрипта ===", ['deal_id' => $dealId]);

// Получаем информацию о текущей сделке
$dealInfoResponse = bitrixRestCall($webhookUrl . 'crm.deal.get', [
    'id' => $dealId
]);

if (isset($dealInfoResponse['error'])) {
    debugLog("ERROR → Не удалось получить сделку", $dealInfoResponse);
    die("Ошибка при получении информации о сделке");
}

$stageId          = $dealInfoResponse['result']['STAGE_ID'];
$categoryId       = $dealInfoResponse['result']['CATEGORY_ID'];
$contactId        = $dealInfoResponse['result']['CONTACT_ID'];
$currentDealTitle = $dealInfoResponse['result']['TITLE'];

debugLog("Данные сделки", [
    'stage_id'    => $stageId,
    'category_id' => $categoryId,
    'contact_id'  => $contactId,
    'title'       => $currentDealTitle,
]);


// Получаем список стадий текущей воронки
$stagesResponse = bitrixRestCall($webhookUrl . 'crm.dealcategory.stage.list', [
    'id' => $categoryId
]);

if (isset($stagesResponse['error'])) {
    debugLog("ERROR → Не удалось получить стадии", $stagesResponse);
    die("Ошибка при получении списка стадий");
}

// Формируем массив стадий
$stageNames = [];
foreach ($stagesResponse['result'] as $stage) {
    $stageNames[$stage['STATUS_ID']] = $stage['NAME'];
}

debugLog("Стадии воронки", $stageNames);


// Формируем фильтр для поиска активных сделок в текущей воронке
$filter = [
    'CATEGORY_ID'          => $categoryId,
    '!STAGE_SEMANTIC_ID'   => ['S', 'F'], // исключаем закрытые
    '!ID'                  => $dealId
];

$existingDeals = [];
$start         = 0;
$page          = 1;

do {
    debugLog("Запрос страницы #{$page} сделок", ['start' => $start]);

    $dealsResponse = bitrixRestCall($webhookUrl . 'crm.deal.list', [
        'filter' => $filter,
        'select' => ['ID', 'STAGE_ID', 'CONTACT_ID'],
        'start'  => $start
    ]);

    if (isset($dealsResponse['error'])) {
        debugLog("ERROR → Не удалось получить список сделок", $dealsResponse);
        die("Ошибка при получении списка сделок");
    }

    if (!empty($dealsResponse['result'])) {
        $existingDeals = array_merge($existingDeals, $dealsResponse['result']);
        $start += 50;
        $page++;
    } else {
        break;
    }

} while (true);

debugLog("Всего найдено активных сделок в воронке", ['count' => count($existingDeals)]);


// Проверяем наличие дублей
$isDuplicate        = false;
$duplicateDealsInfo = [];

foreach ($existingDeals as $deal) {

    if ($deal['CONTACT_ID'] == $contactId && !empty($contactId)) {

        $isDuplicate = true;

        $duplicateDealsInfo[] = [
            'id'         => $deal['ID'],
            'stage_id'   => $deal['STAGE_ID'],
            'stage_name' => $stageNames[$deal['STAGE_ID']] ?? 'Неизвестная стадия'
        ];
    }
}

debugLog("Результат проверки дублей", [
    'is_duplicate'   => $isDuplicate,
    'duplicates'     => $duplicateDealsInfo,
]);


// Если найден дубль — обновляем название и добавляем комментарий
if ($isDuplicate && strpos($currentDealTitle, 'ДУБЛЬ!') === false) {

    $newTitle = $currentDealTitle . ' ДУБЛЬ!';

    // Обновляем название сделки
    $updateResponse = bitrixRestCall($webhookUrl . 'crm.deal.update', [
        'id'     => $dealId,
        'fields' => [
            'TITLE' => $newTitle
        ]
    ]);

    if (isset($updateResponse['error'])) {
        debugLog("ERROR → Не удалось обновить название сделки", $updateResponse);
        die("Ошибка при обновлении названия сделки");
    }

    debugLog("Название сделки обновлено", ['new_title' => $newTitle]);

    // Формируем комментарий
    $comment  = "ВНИМАНИЕ: ДУБЛЬ СДЕЛКИ!\n";
    $comment .= "Найдены активные сделки с тем же контактом (ID: {$contactId}):\n\n";

    foreach ($duplicateDealsInfo as $dealInfo) {
        $comment .= "Сделка ID: {$dealInfo['id']} | Стадия: {$dealInfo['stage_name']}\n";
    }

    // Добавляем комментарий
    $commentResponse = bitrixRestCall($webhookUrl . 'crm.timeline.comment.add', [
        'fields' => [
            'ENTITY_ID'   => $dealId,
            'ENTITY_TYPE' => 'deal',
            'COMMENT'     => $comment
        ]
    ]);

    if (isset($commentResponse['error'])) {
        debugLog("ERROR → Не удалось добавить комментарий", $commentResponse);
        die("Ошибка при добавлении комментария");
    }

    debugLog("Комментарий добавлен");
}


// Ответ
$result = [
    "status"              => "ok",
    "is_duplicate"        => $isDuplicate,
    "duplicate_deals"     => $duplicateDealsInfo,
    "total_deals_checked" => count($existingDeals)
];

debugLog("=== Завершение скрипта ===", $result);

echo json_encode($result);
