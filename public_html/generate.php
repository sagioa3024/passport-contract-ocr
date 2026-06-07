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
    if (!class_exists('DOMDocument')) {
        return $xml;
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    $loaded = @$dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return $xml;
    }

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

        if (strpos($text, 'Заказчик\Слушатель') !== false || strpos($text, 'Заказчик/Слушатель') !== false) {
            replaceParagraphText($paragraph, 'Заказчик\Слушатель' . "\t    " . '____________ /' . $data['signature'] . '/');
            continue;
        }
    }

    return $dom->saveXML();
}

function le16($bytes, $offset)
{
    $value = unpack('v', substr($bytes, $offset, 2));
    return $value ? $value[1] : 0;
}

function le32($bytes, $offset)
{
    $value = unpack('V', substr($bytes, $offset, 4));
    return $value ? $value[1] : 0;
}

function loadTemplateBytes($templatePath, &$error)
{
    if (is_readable($templatePath)) {
        $bytes = @file_get_contents($templatePath);
        if ($bytes !== false && strlen($bytes) > 1000 && substr($bytes, 0, 2) === 'PK') {
            return $bytes;
        }
    }

    $base64Path = __DIR__ . '/contract_template.base64.txt';
    if (is_readable($base64Path)) {
        $base64 = preg_replace('/\s+/', '', (string)@file_get_contents($base64Path));
        $bytes = base64_decode($base64, true);
        if ($bytes !== false && strlen($bytes) > 1000 && substr($bytes, 0, 2) === 'PK') {
            return $bytes;
        }
    }

    $error = 'Не удалось прочитать полный шаблон договора contract_template.docx.';
    return false;
}

function readDocxEntries($bytes, &$error)
{
    $eocd = strrpos($bytes, "\x50\x4b\x05\x06");
    if ($eocd === false) {
        $error = 'Шаблон договора не похож на DOCX/ZIP.';
        return false;
    }

    $totalEntries = le16($bytes, $eocd + 10);
    $centralOffset = le32($bytes, $eocd + 16);
    $entries = array();
    $pos = $centralOffset;

    for ($i = 0; $i < $totalEntries; $i++) {
        if (substr($bytes, $pos, 4) !== "\x50\x4b\x01\x02") {
            $error = 'Не удалось прочитать структуру шаблона договора.';
            return false;
        }

        $flags = le16($bytes, $pos + 8);
        $method = le16($bytes, $pos + 10);
        $compressedSize = le32($bytes, $pos + 20);
        $nameLength = le16($bytes, $pos + 28);
        $extraLength = le16($bytes, $pos + 30);
        $commentLength = le16($bytes, $pos + 32);
        $localOffset = le32($bytes, $pos + 42);
        $name = substr($bytes, $pos + 46, $nameLength);

        if (substr($bytes, $localOffset, 4) !== "\x50\x4b\x03\x04") {
            $error = 'Не удалось прочитать файл внутри шаблона договора.';
            return false;
        }

        $localNameLength = le16($bytes, $localOffset + 26);
        $localExtraLength = le16($bytes, $localOffset + 28);
        $dataStart = $localOffset + 30 + $localNameLength + $localExtraLength;
        $compressed = substr($bytes, $dataStart, $compressedSize);

        if ($method === 0) {
            $content = $compressed;
        } elseif ($method === 8) {
            $content = @gzinflate($compressed);
            if ($content === false) {
                $error = 'Не удалось распаковать часть шаблона договора.';
                return false;
            }
        } else {
            $error = 'Шаблон договора использует неподдерживаемое сжатие ZIP.';
            return false;
        }

        $entries[] = array(
            'name' => $name,
            'content' => $content,
            'flags' => $flags,
        );

        $pos += 46 + $nameLength + $extraLength + $commentLength;
    }

    return $entries;
}

function dosTimeDate()
{
    $time = time();
    $hour = (int)date('G', $time);
    $minute = (int)date('i', $time);
    $second = (int)date('s', $time);
    $year = max(1980, (int)date('Y', $time));
    $month = (int)date('n', $time);
    $day = (int)date('j', $time);

    $dosTime = ($hour << 11) | ($minute << 5) | (int)floor($second / 2);
    $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;
    return array($dosTime, $dosDate);
}

function buildDocxBytes($entries, &$error)
{
    list($dosTime, $dosDate) = dosTimeDate();
    $local = '';
    $central = '';
    $offset = 0;

    foreach ($entries as $entry) {
        $name = $entry['name'];
        $content = $entry['content'];
        $method = ($content === '' || substr($name, -1) === '/') ? 0 : 8;
        $compressed = $method === 8 ? gzdeflate($content, 6) : $content;
        if ($compressed === false) {
            $error = 'Не удалось сжать готовый договор.';
            return false;
        }

        $crc = crc32($content);
        $compressedSize = strlen($compressed);
        $uncompressedSize = strlen($content);
        $nameLength = strlen($name);

        $localHeader = pack('V', 0x04034b50)
            . pack('v', 20)
            . pack('v', 0)
            . pack('v', $method)
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $compressedSize)
            . pack('V', $uncompressedSize)
            . pack('v', $nameLength)
            . pack('v', 0)
            . $name;

        $centralHeader = pack('V', 0x02014b50)
            . pack('v', 20)
            . pack('v', 20)
            . pack('v', 0)
            . pack('v', $method)
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $compressedSize)
            . pack('V', $uncompressedSize)
            . pack('v', $nameLength)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', 0)
            . pack('V', $offset)
            . $name;

        $local .= $localHeader . $compressed;
        $central .= $centralHeader;
        $offset += strlen($localHeader) + $compressedSize;
    }

    $centralOffset = strlen($local);
    $centralSize = strlen($central);
    $count = count($entries);

    $eocd = pack('V', 0x06054b50)
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', $count)
        . pack('v', $count)
        . pack('V', $centralSize)
        . pack('V', $centralOffset)
        . pack('v', 0);

    return $local . $central . $eocd;
}

function createContractDocx($templateBytes, $data, &$error)
{
    $entries = readDocxEntries($templateBytes, $error);
    if ($entries === false) {
        return false;
    }

    $foundDocument = false;
    foreach ($entries as $index => $entry) {
        if ($entry['name'] === 'word/document.xml') {
            $entries[$index]['content'] = fillDocumentXml($entry['content'], $data);
            $foundDocument = true;
            break;
        }
    }

    if (!$foundDocument) {
        $error = 'В шаблоне договора не найден word/document.xml.';
        return false;
    }

    return buildDocxBytes($entries, $error);
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

$data = array(
    'city' => $city,
    'contract_date' => $contractDate,
    'customer_paragraph' => $customerParagraph,
    'signature' => signatureName($fullName),
);

$error = '';
$templateBytes = loadTemplateBytes($templatePath, $error);
if ($templateBytes === false) {
    setStatus(500);
    echo $error;
    exit;
}

$docxBytes = createContractDocx($templateBytes, $data, $error);
if ($docxBytes === false) {
    setStatus(500);
    echo $error !== '' ? $error : 'Не удалось сформировать договор.';
    exit;
}

if (file_put_contents($path, $docxBytes) === false) {
    setStatus(500);
    echo 'Не удалось записать готовый договор.';
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
