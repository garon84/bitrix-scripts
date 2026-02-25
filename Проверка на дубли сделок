<?php

$dealId = {{ID}}; // ID текущей сделки

// Вебхук Bitrix24
$webhookUrl = 'https://exemple.com/rest/1/rest/';

// Получаем информацию о текущей сделке
$dealInfoResponse = bitrixRestCall($webhookUrl . 'crm.deal.get', [
    'id' => $dealId
]);

if (isset($dealInfoResponse['error'])) {
    die("Ошибка при получении информации о сделке");
}

$stageId         = $dealInfoResponse['result']['STAGE_ID'];
$categoryId      = $dealInfoResponse['result']['CATEGORY_ID'];
$contactId       = $dealInfoResponse['result']['CONTACT_ID'];
$currentDealTitle = $dealInfoResponse['result']['TITLE'];


// Получаем список стадий текущей воронки
$stagesResponse = bitrixRestCall($webhookUrl . 'crm.dealcategory.stage.list', [
    'id' => $categoryId
]);

if (isset($stagesResponse['error'])) {
    die("Ошибка при получении списка стадий");
}

// Формируем массив стадий
$stageNames = [];
foreach ($stagesResponse['result'] as $stage) {
    $stageNames[$stage['STATUS_ID']] = $stage['NAME'];
}


// Формируем фильтр для поиска активных сделок в текущей воронке
$filter = [
    'CATEGORY_ID' => $categoryId,
    '!STAGE_SEMANTIC_ID' => ['S', 'F'], // исключаем закрытые
    '!ID' => $dealId
];

$existingDeals = [];
$start = 0;

do {

    $dealsResponse = bitrixRestCall($webhookUrl . 'crm.deal.list', [
        'filter' => $filter,
        'select' => ['ID', 'STAGE_ID', 'CONTACT_ID'],
        'start'  => $start
    ]);

    if (isset($dealsResponse['error'])) {
        die("Ошибка при получении списка сделок");
    }

    if (!empty($dealsResponse['result'])) {
        $existingDeals = array_merge($existingDeals, $dealsResponse['result']);
        $start += 50;
    } else {
        break;
    }

} while (true);


// Проверяем наличие дублей
$isDuplicate = false;
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


// Если найден дубль — обновляем название и добавляем комментарий
if ($isDuplicate && strpos($currentDealTitle, 'ДУБЛЬ!') === false) {

    $newTitle = $currentDealTitle . ' ДУБЛЬ!';

    // Обновляем название сделки
    $updateResponse = bitrixRestCall($webhookUrl . 'crm.deal.update', [
        'id' => $dealId,
        'fields' => [
            'TITLE' => $newTitle
        ]
    ]);

    if (isset($updateResponse['error'])) {
        die("Ошибка при обновлении названия сделки");
    }

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
        die("Ошибка при добавлении комментария");
    }
}


// Ответ
echo json_encode([
    "status"              => "ok",
    "is_duplicate"        => $isDuplicate,
    "duplicate_deals"     => $duplicateDealsInfo,
    "total_deals_checked" => count($existingDeals)
]);


// Функция вызова REST API
function bitrixRestCall($url, $params)
{
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
        die("Ошибка соединения с REST API");
    }

    return json_decode($result, true);
}

?>
