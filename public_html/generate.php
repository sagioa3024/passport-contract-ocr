<?php

function setStatus($code)
{
    $texts = array(
        400 => 'Bad Request',
        500 => 'Internal Server Error',
    );
    $text = isset($texts[$code]) ? $texts[$code] : 'Error';
    header('HTTP/1.1 ' . $code . ' ' . $text);
}

function value($key)
{
    return trim((string)(isset($_POST[$key]) ? $_POST[$key] : ''));
}

function humanDate($date)
{
    if ($date === '') {
        return '';
    }

    $time = strtotime($date);
    return $time ? date('d.m.Y', $time) : $date;
}

function contractDateText($date)
{
    if ($date === '') {
        return date('d.m.Y');
    }
    return humanDate($date);
}

function quotedDate($date)
{
    if ($date === '') {
        return '«____» __________ ____';
    }

    $time = strtotime($date);
    if (!$time) {
        return $date;
    }

    $months = array(
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    );

    return '«' . date('d', $time) . '» ' . $months[(int)date('n', $time)] . ' ' . date('Y', $time);
}

function safeFilenamePart($value)
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'client';
}

function signatureName($fullName)
{
    $parts = preg_split('/\s+/u', trim($fullName));
    if (!$parts || count($parts) === 0) {
        return $fullName;
    }

    $lastName = $parts[0];
    $initials = '';
    if (isset($parts[1]) && $parts[1] !== '') {
        preg_match('/^./u', $parts[1], $match);
        $initials .= (isset($match[0]) ? $match[0] : '') . '.';
    }
    if (isset($parts[2]) && $parts[2] !== '') {
        preg_match('/^./u', $parts[2], $match);
        $initials .= (isset($match[0]) ? $match[0] : '') . '.';
    }

    return trim($initials . ' ' . $lastName);
}

function paragraphText($paragraph)
{
    $text = '';
    $nodes = $paragraph->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
    foreach ($nodes as $node) {
        $text .= $node->nodeValue;
    }
    return $text;
}

function replaceParagraphText($paragraph, $text)
{
    $nodes = $paragraph->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
    if ($nodes->length === 0) {
        return;
    }

    $nodes->item(0)->nodeValue = $text;
    for ($i = 1; $i < $nodes->length; $i++) {
        $nodes->item($i)->nodeValue = '';
    }
}

function hasAllText($text, $needles)
{
    foreach ($needles as $needle) {
        if (strpos($text, $needle) === false) {
            return false;
        }
    }
    return true;
}

function fillDocumentXml($xml, $data)
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    $dom->loadXML($xml);

    $paragraphs = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p');
    foreach ($paragraphs as $paragraph) {
        $text = paragraphText($paragraph);

        if (hasAllText($text, array('г. Новороссийск', '10 января 2026')) || strpos($text, '10 января 2026г') !== false) {
            replaceParagraphText($paragraph, 'г. ' . $data['city'] . "\t\t\t\t\t" . $data['contract_date'] . ' г.');
            continue;
        }

        if (hasAllText($text, array('гр. РФ', 'Петров Петр Петрович')) || hasAllText($text, array('паспорт серия', 'проживающий'))) {
            replaceParagraphText($paragraph, $data['customer_paragraph']);
            continue;
        }

        if (strpos($text, 'Заказчик\\Слушатель') !== false || strpos($text, 'Заказчик/Слушатель') !== false) {
            replaceParagraphText($paragraph, 'Заказчик\\Слушатель' . "\t    " . '____________ /' . $data['signature'] . '/');
            continue;
        }
    }

    return $dom->saveXML();
}

function openTemplateArchive($zip, $templatePath)
{
    $result = @$zip->open($templatePath);
    if ($result === true) {
        return true;
    }

    if (is_readable($templatePath)) {
        $localBytes = @file_get_contents($templatePath);
        if ($localBytes !== false && strlen($localBytes) > 1000) {
            $localFallbackPath = sys_get_temp_dir() . '/contract_template_local_' . md5($localBytes) . '.docx';
            if (file_put_contents($localFallbackPath, $localBytes) !== false) {
                $localResult = @$zip->open($localFallbackPath);
                if ($localResult === true) {
                    return true;
                }
                $result = $localResult;
            }
        }
    }

    $rawUrl = 'https://raw.githubusercontent.com/sagioa3024/passport-contract-ocr/main/public_html/contract_template.docx';
    $rawBytes = @file_get_contents($rawUrl);
    if ($rawBytes !== false && strlen($rawBytes) > 1000) {
        $rawFallbackPath = sys_get_temp_dir() . '/contract_template_raw_' . md5($rawBytes) . '.docx';
        if (file_put_contents($rawFallbackPath, $rawBytes) !== false) {
            $rawResult = @$zip->open($rawFallbackPath);
            if ($rawResult === true) {
                return true;
            }
            $result = $rawResult;
        }
    }

    $base64Path = __DIR__ . '/contract_template.base64.txt';
    if (!file_exists($base64Path)) {
        return $result;
    }

    $base64 = preg_replace('/\s+/', '', (string)file_get_contents($base64Path));
    $bytes = base64_decode($base64, true);
    if ($bytes === false) {
        return $result;
    }

    $fallbackPath = sys_get_temp_dir() . '/contract_template_' . md5($base64) . '.docx';
    if (file_put_contents($fallbackPath, $bytes) === false) {
        return $result;
    }

    $base64Result = @$zip->open($fallbackPath);
    return $base64Result === true ? true : $base64Result;
}

if (empty($_POST['consent'])) {
    setStatus(400);
    echo 'Нужно подтвердить согласие и проверку данных.';
    exit;
}

$fullName = value('full_name');
if ($fullName === '') {
    setStatus(400);
    echo 'Заполните ФИО.';
    exit;
}

$city = 'Новороссийск';
$contractDate = contractDateText(value('contract_date'));
$birthDate = humanDate(value('birth_date'));
$issueDate = value('issue_date');
$series = value('passport_series');
$number = value('passport_number');
$issuedBy = value('issued_by');
$departmentCode = value('department_code');
$address = value('registration_address');
$birthPlace = value('birth_place');
$phone = value('phone');

$passportDetails = 'паспорт серия ' . ($series !== '' ? $series : '__________') . ' №' . ($number !== '' ? $number : '____________');
$issuedDetails = 'выдан ' . quotedDate($issueDate) . ' года, ' . ($issuedBy !== '' ? $issuedBy : '_______________________');
if ($departmentCode !== '') {
    $issuedDetails .= ', код подразделения ' . $departmentCode;
}

$customerParagraph = 'Индивидуальный предприниматель Финтисов Михаил Сергеевич (Учебный центр РОСТ) ОГРНИП 318237500147635, ИНН 231501144923 Регистрационный номер лицензии на образовательную деятельность № Л035-01218-23/00243153 от 03.02.2021, именуемый в дальнейшем «Исполнитель», с одной стороны, и гр. РФ ' . $fullName . ' ' . ($birthDate !== '' ? $birthDate : '_______') . ' года рождения, место рождения ' . ($birthPlace !== '' ? $birthPlace : '_______________') . ', ' . $passportDetails . ', ' . $issuedDetails . ', проживающий(ая) по адресу ' . ($address !== '' ? $address : '______________________________') . ', телефон: ' . ($phone !== '' ? $phone : '______________') . ', именуемый в дальнейшем «Заказчик», с другой стороны заключили между собой настоящий договор о нижеследующем.';

$templatePath = __DIR__ . '/contract_template.docx';

$generatedDir = __DIR__ . '/generated';
if (!is_dir($generatedDir) && !mkdir($generatedDir, 0755, true)) {
    setStatus(500);
    echo 'Не удалось создать папку для готовых договоров.';
    exit;
}
if (!is_writable($generatedDir)) {
    setStatus(500);
    echo 'Папка для готовых договоров недоступна для записи.';
    exit;
}

$safeName = safeFilenamePart($fullName);
$filename = 'contract_' . $safeName . '_' . date('Ymd_His') . '.docx';
$path = $generatedDir . '/' . $filename;

if (!class_exists('ZipArchive')) {
    setStatus(500);
    echo 'На сервере не подключено PHP-расширение ZipArchive для создания DOCX.';
    exit;
}

$source = new ZipArchive();
$templateOpenResult = openTemplateArchive($source, $templatePath);
$data = array(
    'city' => $city,
    'contract_date' => $contractDate,
    'customer_paragraph' => $customerParagraph,
    'signature' => signatureName($fullName),
);

if ($templateOpenResult !== true) {
    setStatus(500);
    echo 'Не удалось открыть полный шаблон договора contract_template.docx. Код: ' . $templateOpenResult;
    exit;
} else {
    $target = new ZipArchive();
    if ($target->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $source->close();
        setStatus(500);
        echo 'Не удалось создать готовый договор.';
        exit;
    }

    for ($i = 0; $i < $source->numFiles; $i++) {
        $name = $source->getNameIndex($i);
        $content = $source->getFromIndex($i);
        if ($name === 'word/document.xml') {
            $content = fillDocumentXml($content, $data);
        }
        $target->addFromString($name, $content);
    }

    $source->close();
    $target->close();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
