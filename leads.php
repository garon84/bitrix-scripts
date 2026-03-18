<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

function logger($data) {
    $log = date("Y.m.d H:i:s") . " : " . print_r($data, true);
    file_put_contents("testmaster.log", $log . PHP_EOL, FILE_APPEND);
}


$entityBody = json_decode(file_get_contents('php://input'), true);
logger(['Incoming Request', $entityBody]);

if (!is_array($entityBody)) {
    $error = ['error' => 'Invalid input data'];
    logger($error);
    echo json_encode($error);
    exit;
}

$webhook = 'https://sillan-masterok.kz/rest/6841/cfac72j2hetmb5oj/';
$name = $entityBody['name'] ?? '';
$phone = $entityBody['phone'] ?? '';
//$product_text = $entityBody['textarea'] ?? '';
$product_color = $entityBody['Категория'] ?? '';
$product_usertype = $entityBody['usertype'] ?? '';
$product_company = $entityBody['company'] ?? '';

//$utm_medium = $entityBody['utm_medium'] ?? '';
//$utm_source = $entityBody['utm_source'] ?? '';
//$utm_content = $entityBody['utm_content'] ?? '';
//$utm_term = $entityBody['utm_term'] ?? '';
//$utm_campaign = $entityBody['utm_campaign'] ?? '';


if (empty($name)) {
    $error = ['error' => 'Name is required'];
    logger($error);
    echo json_encode($error);
    exit;
}
if (empty($phone)) {
    $error = ['error' => 'Phone is required'];
    logger($error);
    echo json_encode($error);
    exit;
}


$phone2 = preg_replace('/\D/', '', $phone);
$phone2 = '+' . $phone2;
logger(['Cleaned phone', $phone2]);


$searchContactUrl = $webhook . 'crm.contact.list';
$searchData = [
    'filter' => ['PHONE' => $phone2],
    'select' => ['ID']
];

$ch = curl_init($searchContactUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($searchData));
$searchResponse = curl_exec($ch);
if ($searchResponse === false) {
    $error = ['error' => 'Search contact failed: ' . curl_error($ch)];
    logger($error);
    echo json_encode($error);
    curl_close($ch);
    exit;
}
curl_close($ch);


logger(['Search Response', $searchResponse]);

$searchResult = json_decode($searchResponse, true);
$contactId = $searchResult['result'][0]['ID'] ?? null;

if (!$contactId) {

    $contactData = [
        'fields' => [
            'NAME' => $name,
            'PHONE' => [['VALUE' => $phone2, 'VALUE_TYPE' => 'WORK']]
        ]
    ];

    $contactUrl = $webhook . 'crm.contact.add';
    $ch = curl_init($contactUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($contactData));
    $contactResponse = curl_exec($ch);
    if ($contactResponse === false) {
        $error = ['error' => 'Create contact failed: ' . curl_error($ch)];
        logger($error);
        echo json_encode($error);
        curl_close($ch);
        exit;
    }
    curl_close($ch);


    logger(['Contact Creation Response', $contactResponse]);

    $contactResult = json_decode($contactResponse, true);
    $contactId = $contactResult['result'] ?? null;

    if (!$contactId) {
        $error = ['error' => 'Failed to create contact'];
        logger($error);
        echo json_encode($error);
        exit;
    }
}


logger(['Contact ID', $contactId]);


$dealData = [
    'fields' => [
        'TITLE' => "Новая заявка с сайта Инстаграм \n $name " . $phone,
//        'COMMENTS' => $product_text,
        'CONTACT_ID' => $contactId,
//        'UF_CRM_1754912559118' => $product_text,
        "UF_CRM_1754912543193"=>  $product_usertype,
        "UF_CRM_1754912551685"=>  $product_color,
        "SOURCE_DESCRIPTION"=>  $product_company,
        "UF_CRM_1754507894"=> "1901", //fb источник маркетинг
//        "UTM_SOURCE"=>  $utm_source,
//        "UTM_MEDIUM"=>  $utm_medium,
//        "UTM_CAMPAIGN"=>  $utm_campaign,
//        "UTM_CONTENT"=>  $utm_content,
//        "UTM_TERM" => $utm_term,
    ]
];

$dealUrl = $webhook . 'crm.lead.add';
$ch = curl_init($dealUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dealData));
$dealResponse = curl_exec($ch);
if ($dealResponse === false) {
    $error = ['error' => 'Create deal failed: ' . curl_error($ch)];
    logger($error);
    echo json_encode($error);
    curl_close($ch);
    exit;
}
curl_close($ch);

logger(['Lead Creation Response', $dealResponse]);

$dealResult = json_decode($dealResponse, true);
if (empty($dealResult['result'])) {
    $error = ['error' => 'Failed to create deal', 'response' => $dealResponse];
    logger($error);
    echo json_encode($error);
    exit;
}


$success = ['success' => true, 'deal_id' => $dealResult['result']];
logger($success);

echo json_encode($success);
