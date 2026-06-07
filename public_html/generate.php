<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function setStatus($code)
{
    $texts = array(400 => 'Bad Request', 500 => 'Internal Server Error');
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

function parsedDate($date)
{
    if ($date === '') {
        return false;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match)) {
        return mktime(0, 0, 0, (int)$match[2], (int)$match[3], (int)$match[1]);
    }

    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $match)) {
        return mktime(0, 0, 0, (int)$match[2], (int)$match[1], (int)$match[3]);
    }

    $time = strtotime($date);
    return $time ? $time : false;
}

function humanDate($date)
{
    if ($date === '') {
        return '';
    }

    $time = parsedDate($date);
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

    $time = parsedDate($date);
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

function runPythonContract($scriptPath, $payload, &$error)
{
    if (!function_exists('proc_open')) {
        $error = 'На сервере отключен proc_open для запуска генератора договора.';
        return false;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $error = 'Не удалось подготовить данные договора для генератора.';
        return false;
    }

    foreach (array('python3', 'python') as $python) {
        $cmd = $python . ' ' . escapeshellarg($scriptPath);
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = @proc_open($cmd, $descriptors, $pipes, __DIR__);
        if (!is_resource($process)) {
            continue;
        }

        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code === 0) {
            return true;
        }

        $error = trim($stderr !== '' ? $stderr : $stdout);
        if ($error === '') {
            $error = 'Python-генератор договора завершился с кодом ' . $code . '.';
        }
    }

    if ($error === '') {
        $error = 'Python не найден на сервере.';
    }
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
$scriptPath = __DIR__ . '/contract_fill.py';
$generatedDir = __DIR__ . '/generated';

if (!is_readable($scriptPath)) {
    fail('Не найден генератор договора contract_fill.py.');
}
if (!is_readable($templatePath) && !is_readable(__DIR__ . '/contract_template.base64.txt')) {
    fail('Не найден шаблон договора contract_template.docx.');
}
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

$payload = array(
    'template_path' => $templatePath,
    'output_path' => $path,
    'data' => $data,
);

$error = '';
if (!runPythonContract($scriptPath, $payload, $error)) {
    fail('Не удалось сформировать договор через Python. ' . $error);
}

if (!is_readable($path) || filesize($path) <= 1000) {
    fail('Готовый договор не был создан корректно.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
