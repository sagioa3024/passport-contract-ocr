<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function setStatus($code)
{
    $texts = array(
        400 => 'Bad Request',
        500 => 'Internal Server Error',
    );
    $text = isset($texts[$code]) ? $texts[$code] : 'Error';
    header('HTTP/1.1 ' . $code . ' ' . $text);
}

function fail($message, $code = 500)
{
    setStatus($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
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
    return $date === '' ? date('d.m.Y') : humanDate($date);
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

function fillDocumentXml($xml, $data, &$error)
{
    if (!class_exists('DOMDocument')) {
        $error = 'На сервере нет PHP DOM для заполнения DOCX.';
        return false;
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    $loaded = @$dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $error = 'Не удалось прочитать XML внутри шаблона договора.';
        return false;
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

        if (strpos($text, 'Заказчик') !== false && strpos($text, 'Слушатель') !== false) {
            replaceParagraphText($paragraph, 'Заказчик\Слушатель' . "\t    " . '____________ /' . $data['signature'] . '/');
            continue;
        }
    }

    return $dom->saveXML();
}

function runCommand($command, $cwd, &$stdout, &$stderr)
{
    $stdout = '';
    $stderr = '';
    if (!function_exists('proc_open')) {
        return 127;
    }

    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = @proc_open($command, $descriptors, $pipes, $cwd ? $cwd : null);
    if (!is_resource($process)) {
        return 127;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return proc_close($process);
}

function commandExists($command)
{
    $out = '';
    $err = '';
    return runCommand('command -v ' . escapeshellarg($command), null, $out, $err) === 0;
}

function deleteTree($path)
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!$items) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            deleteTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function validDocxBytes($bytes)
{
    return is_string($bytes) && strlen($bytes) > 1000 && substr($bytes, 0, 2) === 'PK';
}

function addTemplateCandidate(&$candidates, $label, $bytes)
{
    if (!validDocxBytes($bytes)) {
        return;
    }
    $hash = md5($bytes);
    foreach ($candidates as $candidate) {
        if ($candidate['hash'] === $hash) {
            return;
        }
    }
    $candidates[] = array('label' => $label, 'bytes' => $bytes, 'hash' => $hash);
}

function fetchUrlBytes($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ));
        $bytes = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (is_string($bytes) && $code >= 200 && $code < 400) {
            return $bytes;
        }
    }

    $bytes = @file_get_contents($url);
    return is_string($bytes) ? $bytes : false;
}

function loadTemplateCandidates($templatePath)
{
    $candidates = array();

    $base64Path = __DIR__ . '/contract_template.base64.txt';
    if (is_readable($base64Path)) {
        $base64 = preg_replace('/\s+/', '', (string)@file_get_contents($base64Path));
        addTemplateCandidate($candidates, 'base64', base64_decode($base64, true));
    }

    if (is_readable($templatePath)) {
        addTemplateCandidate($candidates, 'local', @file_get_contents($templatePath));
    }

    $rawUrl = 'https://raw.githubusercontent.com/sagioa3024/passport-contract-ocr/main/public_html/contract_template.docx';
    addTemplateCandidate($candidates, 'github-raw', fetchUrlBytes($rawUrl));

    return $candidates;
}

function createDocxWithCliZip($templateBytes, $targetPath, $data, &$error)
{
    if (!commandExists('unzip') || !commandExists('zip')) {
        $error = 'На сервере нет команд zip/unzip.';
        return false;
    }

    $workDir = sys_get_temp_dir() . '/contract_docx_' . str_replace('.', '', uniqid('', true));
    if (!mkdir($workDir, 0700, true)) {
        $error = 'Не удалось создать временную папку для договора.';
        return false;
    }

    $templateFile = $workDir . '/template.docx';
    if (file_put_contents($templateFile, $templateBytes) === false) {
        deleteTree($workDir);
        $error = 'Не удалось записать временный шаблон договора.';
        return false;
    }

    $stdout = '';
    $stderr = '';
    $code = runCommand('unzip -p ' . escapeshellarg($templateFile) . ' word/document.xml', null, $stdout, $stderr);
    if ($code !== 0 || $stdout === '') {
        deleteTree($workDir);
        $error = 'unzip не смог прочитать word/document.xml: ' . trim($stderr);
        return false;
    }

    $xml = fillDocumentXml($stdout, $data, $error);
    if ($xml === false) {
        deleteTree($workDir);
        return false;
    }

    if (!copy($templateFile, $targetPath)) {
        deleteTree($workDir);
        $error = 'Не удалось создать копию шаблона договора.';
        return false;
    }

    $wordDir = $workDir . '/word';
    if (!mkdir($wordDir, 0700, true)) {
        deleteTree($workDir);
        $error = 'Не удалось создать временную папку word.';
        return false;
    }
    if (file_put_contents($wordDir . '/document.xml', $xml) === false) {
        deleteTree($workDir);
        $error = 'Не удалось записать обновленный document.xml.';
        return false;
    }

    $code = runCommand('zip -q -u ' . escapeshellarg($targetPath) . ' word/document.xml', $workDir, $stdout, $stderr);
    if ($code !== 0) {
        deleteTree($workDir);
        $error = 'zip не смог обновить DOCX: ' . trim($stderr);
        return false;
    }

    $code = runCommand('unzip -t ' . escapeshellarg($targetPath), null, $stdout, $stderr);
    if ($code !== 0) {
        deleteTree($workDir);
        $error = 'Готовый DOCX не прошел проверку unzip: ' . trim($stdout . ' ' . $stderr);
        return false;
    }

    deleteTree($workDir);
    return true;
}

function createDocxWithZipArchive($templateBytes, $targetPath, $data, &$error)
{
    if (!class_exists('ZipArchive')) {
        $error = 'На сервере нет ZipArchive.';
        return false;
    }

    $templateFile = sys_get_temp_dir() . '/contract_template_' . md5($templateBytes) . '.docx';
    if (file_put_contents($templateFile, $templateBytes) === false) {
        $error = 'Не удалось записать временный шаблон DOCX.';
        return false;
    }

    $source = new ZipArchive();
    $openCode = @$source->open($templateFile);
    if ($openCode !== true) {
        @unlink($templateFile);
        $error = 'ZipArchive не смог открыть шаблон. Код: ' . $openCode;
        return false;
    }

    $target = new ZipArchive();
    if ($target->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $source->close();
        @unlink($templateFile);
        $error = 'ZipArchive не смог создать готовый договор.';
        return false;
    }

    for ($i = 0; $i < $source->numFiles; $i++) {
        $name = $source->getNameIndex($i);
        $content = $source->getFromIndex($i);
        if ($content === false) {
            $target->close();
            $source->close();
            @unlink($templateFile);
            $error = 'Не удалось прочитать файл внутри шаблона: ' . $name;
            return false;
        }
        if ($name === 'word/document.xml') {
            $content = fillDocumentXml($content, $data, $error);
            if ($content === false) {
                $target->close();
                $source->close();
                @unlink($templateFile);
                return false;
            }
        }
        $target->addFromString($name, $content);
    }

    $source->close();
    $closed = $target->close();
    @unlink($templateFile);

    if (!$closed) {
        $error = 'ZipArchive не смог сохранить готовый договор.';
        return false;
    }

    return true;
}

function createContractDocx($templateCandidates, $targetPath, $data, &$error)
{
    $errors = array();
    foreach ($templateCandidates as $candidate) {
        $candidateError = '';
        if (createDocxWithCliZip($candidate['bytes'], $targetPath, $data, $candidateError)) {
            return true;
        }
        $errors[] = $candidate['label'] . '/cli: ' . $candidateError;

        $candidateError = '';
        if (createDocxWithZipArchive($candidate['bytes'], $targetPath, $data, $candidateError)) {
            return true;
        }
        $errors[] = $candidate['label'] . '/ziparchive: ' . $candidateError;
    }

    $error = 'Не удалось сформировать договор. ' . implode(' | ', array_slice($errors, 0, 6));
    return false;
}

if (empty($_POST['consent'])) {
    fail('Нужно подтвердить согласие и проверку данных.', 400);
}

$fullName = value('full_name');
if ($fullName === '') {
    fail('Заполните ФИО.', 400);
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
    fail('Не удалось создать папку для готовых договоров.');
}
if (!is_writable($generatedDir)) {
    fail('Папка для готовых договоров недоступна для записи.');
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

$candidates = loadTemplateCandidates($templatePath);
if (count($candidates) === 0) {
    fail('Не найден полный шаблон договора contract_template.docx.');
}

$error = '';
if (!createContractDocx($candidates, $path, $data, $error)) {
    fail($error !== '' ? $error : 'Не удалось сформировать договор.');
}

if (!is_readable($path) || filesize($path) <= 1000) {
    fail('Готовый договор не был создан корректно.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
