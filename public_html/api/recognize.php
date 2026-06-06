<?php

header('Content-Type: application/json; charset=utf-8');

if (!defined('JSON_UNESCAPED_UNICODE')) {
    define('JSON_UNESCAPED_UNICODE', 0);
}

function setStatus($code)
{
    $texts = array(
        400 => 'Bad Request',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    );
    $text = isset($texts[$code]) ? $texts[$code] : 'Error';
    header('HTTP/1.1 ' . $code . ' ' . $text);
}

function cleanRegistrationAddress($value)
{
    $value = preg_replace('/\b\d{1,2}\s+\d{1,2}\s+\d{4}\b/u', '', $value);
    $value = preg_replace('/\b\d{1,2}[\.\/-]\d{1,2}[\.\/-]\d{2,4}\b/u', '', $value);
    $value = preg_replace('/\b\d{4}[\.\/-]\d{1,2}[\.\/-]\d{1,2}\b/u', '', $value);
    $value = preg_replace('/\b(дата|срок|зарегистрирован[а]?|регистрации|прописки|снят[а]?|снятия)\b[:\s]*/iu', '', $value);
    $value = preg_replace('/\s{2,}/u', ' ', $value);
    $value = preg_replace('/\s+,/u', ',', $value);
    $value = preg_replace('/,\s*,+/u', ',', $value);
    return trim($value, " \t\n\r\0\x0B,.;");
}

function onlyDigits($value)
{
    return preg_replace('/\D+/', '', $value);
}

function normalizePassportNumberFields(&$data)
{
    $series = isset($data['passport_series']) ? onlyDigits($data['passport_series']) : '';
    $number = isset($data['passport_number']) ? onlyDigits($data['passport_number']) : '';

    if (strlen($series) === 10 && $number === '') {
        $number = substr($series, 4, 6);
        $series = substr($series, 0, 4);
    }

    if ($series === '' && strlen($number) === 10) {
        $series = substr($number, 0, 4);
        $number = substr($number, 4, 6);
    }

    if (strlen($series . $number) === 10 && strlen($series) !== 4) {
        $combined = $series . $number;
        $series = substr($combined, 0, 4);
        $number = substr($combined, 4, 6);
    }

    $data['passport_series'] = strlen($series) === 4 ? $series : $series;
    $data['passport_number'] = strlen($number) === 6 ? $number : $number;
}

function normalizeUploadedFiles($fieldName)
{
    $items = array();
    if (!isset($_FILES[$fieldName])) {
        return $items;
    }

    $files = $_FILES[$fieldName];
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $items[] = array(
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            );
        }
    } else {
        $items[] = $files;
    }

    return $items;
}

function buildFileContent($file, $mime)
{
    $base64 = base64_encode(file_get_contents($file['tmp_name']));
    $dataUrl = 'data:' . $mime . ';base64,' . $base64;

    if ($mime === 'application/pdf') {
        return array(
            'type' => 'input_file',
            'file_data' => $dataUrl,
            'filename' => $file['name'] ? $file['name'] : 'passport.pdf',
        );
    }

    return array(
        'type' => 'input_image',
        'image_url' => $dataUrl,
        'detail' => 'high',
    );
}

$configPath = __DIR__ . '/../config.php';
$examplePath = __DIR__ . '/../config.example.php';
$config = file_exists($configPath) ? require $configPath : require $examplePath;

$apiKey = trim((string)(isset($config['openai_api_key']) ? $config['openai_api_key'] : ''));
$model = trim((string)(isset($config['openai_model']) ? $config['openai_model'] : 'gpt-4.1'));
if ($model === '' || $model === 'gpt-4.1-mini') {
    $model = 'gpt-4.1';
}
$maxUploadMb = (int)(isset($config['max_upload_mb']) ? $config['max_upload_mb'] : 10);

if ($apiKey === '') {
    setStatus(503);
    echo json_encode(array(
        'ok' => false,
        'error' => 'OpenAI API-ключ не задан. Скопируйте config.example.php в config.php и добавьте ключ.'
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadedFiles = normalizeUploadedFiles('passport');
if (count($uploadedFiles) === 0) {
    setStatus(400);
    echo json_encode(array('ok' => false, 'error' => 'Файл не загружен.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadedFiles = array_slice($uploadedFiles, 0, 4);
$allowed = array('image/jpeg', 'image/png', 'image/webp', 'application/pdf');
$contentParts = array();
$contentParts[] = array(
    'type' => 'input_text',
    'text' => 'Extract ALL visible fields from a Russian internal passport of the Russian Federation. The user may upload one image or several images. One image can be a photocopy sheet containing multiple passport pages at once: identity page, issuing authority page, registration page, or passport spreads placed side by side, upside down, rotated 90 degrees, or in different positions. Treat all visible passport fragments on all uploaded images as one document. Inspect the entire image area, not only the biggest page. Mentally rotate each passport fragment by 0, 90, 180, and 270 degrees. Return empty string only if a field is truly not visible or unreadable. Do not invent values. full_name: surname, given name, patronymic from identity page near photo. birth_date: date of birth, return YYYY-MM-DD. birth_place: place of birth. passport_series: exactly 4 digits; often printed vertically/rotated in red on margins. passport_number: exactly 6 digits; if 10 consecutive digits are visible, first 4 are series and last 6 are number. issued_by: full issuing authority text near issue date. issue_date: return YYYY-MM-DD. department_code: subdivision code in format 000-000. registration_address: from registration stamp, include city/locality, street, house, building/corpus/structure if visible, and apartment if visible. Stamps vary. For house number, prioritize labels meaning house such as dom/d. or the house field near the street. For apartment number, prioritize labels meaning apartment such as kv./kvartira or the apartment field; apartment is mandatory when visible and often sits on the same line/level as the house number or at the far right. Ignore registration/propiska date numbers completely; dates can look like 26 04 2002 or 26.04.2002 and must never become house or apartment. Do not include registration date, expiration date, cancellation date, or words about registration timing in the address.'
);

foreach ($uploadedFiles as $file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        setStatus(400);
        echo json_encode(array('ok' => false, 'error' => 'Один из файлов не загрузился.'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($file['size'] > $maxUploadMb * 1024 * 1024) {
        setStatus(413);
        echo json_encode(array('ok' => false, 'error' => 'Один из файлов слишком большой.'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tmpPath = $file['tmp_name'];
    $mime = mime_content_type($tmpPath);
    if (!$mime) {
        $mime = 'application/octet-stream';
    }

    if (!in_array($mime, $allowed, true)) {
        setStatus(415);
        echo json_encode(array('ok' => false, 'error' => 'Поддерживаются JPG, PNG, WEBP и PDF.'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $contentParts[] = buildFileContent($file, $mime);
}

if (count($contentParts) < 2) {
    setStatus(400);
    echo json_encode(array('ok' => false, 'error' => 'Файл не загружен.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$schema = array(
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => array(
        'full_name' => array('type' => 'string'),
        'birth_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD if visible'),
        'birth_place' => array('type' => 'string'),
        'passport_series' => array('type' => 'string', 'description' => '4 digits of Russian passport series. Often printed vertically/rotated in red on page margins.'),
        'passport_number' => array('type' => 'string', 'description' => '6 digits of Russian passport number. Often printed vertically/rotated in red on page margins.'),
        'issued_by' => array('type' => 'string'),
        'issue_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD if visible'),
        'department_code' => array('type' => 'string'),
        'registration_address' => array('type' => 'string'),
    ),
    'required' => array(
        'full_name',
        'birth_date',
        'birth_place',
        'passport_series',
        'passport_number',
        'issued_by',
        'issue_date',
        'department_code',
        'registration_address',
    ),
);

$payload = array(
    'model' => $model,
    'input' => array(array(
        'role' => 'user',
        'content' => $contentParts,
    )),
    'text' => array(
        'format' => array(
            'type' => 'json_schema',
            'name' => 'passport_data',
            'schema' => $schema,
            'strict' => true,
        ),
    ),
);

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ),
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
));

$raw = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === false || $curlError) {
    setStatus(502);
    echo json_encode(array('ok' => false, 'error' => 'Ошибка соединения с OpenAI API.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$response = json_decode($raw, true);
if ($statusCode >= 400) {
    setStatus(502);
    $message = isset($response['error']['message']) ? $response['error']['message'] : 'OpenAI API вернул ошибку.';
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

$text = isset($response['output_text']) ? $response['output_text'] : '';
if ($text === '' && isset($response['output'])) {
    foreach ($response['output'] as $item) {
        $contents = isset($item['content']) ? $item['content'] : array();
        foreach ($contents as $content) {
            if ((isset($content['type']) ? $content['type'] : '') === 'output_text') {
                $text .= isset($content['text']) ? $content['text'] : '';
            }
        }
    }
}

$data = json_decode($text, true);
if (!is_array($data)) {
    setStatus(502);
    echo json_encode(array('ok' => false, 'error' => 'ИИ вернул ответ в неожиданном формате.'), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($data['registration_address'])) {
    $data['registration_address'] = cleanRegistrationAddress($data['registration_address']);
}

normalizePassportNumberFields($data);

echo json_encode(array('ok' => true, 'data' => $data), JSON_UNESCAPED_UNICODE);
