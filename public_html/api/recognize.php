<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_socket_timeout', '180');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'ok' => false,
            'error' => 'Ошибка OCR на сервере. Подробности записаны в лог Render.'
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

function sendJson($payload, $statusCode)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function failJson($statusCode, $message)
{
    sendJson(array('ok' => false, 'error' => $message), $statusCode);
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

function detectMime($file)
{
    $mime = '';
    if (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($file['tmp_name']);
    }
    if ($mime !== '') {
        return $mime;
    }

    $name = strtolower((string)$file['name']);
    if (preg_match('/\.jpe?g$/', $name)) {
        return 'image/jpeg';
    }
    if (preg_match('/\.png$/', $name)) {
        return 'image/png';
    }
    if (preg_match('/\.webp$/', $name)) {
        return 'image/webp';
    }
    if (preg_match('/\.pdf$/', $name)) {
        return 'application/pdf';
    }
    return 'application/octet-stream';
}

function fileDataUrl($path, $mime)
{
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

function addOriginalPart(&$parts, $file, $mime, $index)
{
    $dataUrl = fileDataUrl($file['tmp_name'], $mime);
    if ($dataUrl === '') {
        return;
    }

    $parts[] = array(
        'type' => 'input_text',
        'text' => 'Upload ' . $index . ': original file. Inspect it as-is and also consider that any passport fragment inside can be rotated.'
    );

    if ($mime === 'application/pdf') {
        $parts[] = array(
            'type' => 'input_file',
            'file_data' => $dataUrl,
            'filename' => $file['name'] ? $file['name'] : ('passport-' . $index . '.pdf'),
        );
        return;
    }

    $parts[] = array(
        'type' => 'input_image',
        'image_url' => $dataUrl,
        'detail' => 'high',
    );
}

function createImageResource($path, $mime)
{
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        return @imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    return false;
}

function copyImageResource($source)
{
    $width = imagesx($source);
    $height = imagesy($source);
    $copy = imagecreatetruecolor($width, $height);
    if (!$copy) {
        return false;
    }
    imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);
    return $copy;
}

function resizeImageIfNeeded($image, $maxSide)
{
    $width = imagesx($image);
    $height = imagesy($image);
    $side = max($width, $height);
    if ($side <= $maxSide) {
        return $image;
    }

    $scale = $maxSide / $side;
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));

    if (function_exists('imagescale')) {
        $scaled = @imagescale($image, $newWidth, $newHeight);
        if ($scaled) {
            imagedestroy($image);
            return $scaled;
        }
    }

    $scaled = imagecreatetruecolor($newWidth, $newHeight);
    if ($scaled) {
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        return $scaled;
    }

    return $image;
}

function addPreparedImageVariants(&$parts, $file, $mime, $index)
{
    if ($mime === 'application/pdf' || !function_exists('imagejpeg')) {
        return;
    }

    $source = createImageResource($file['tmp_name'], $mime);
    if (!$source) {
        return;
    }

    foreach (array(0, 90, 180, 270) as $angle) {
        $image = copyImageResource($source);
        if (!$image) {
            continue;
        }
        if ($angle !== 0) {
            $rotated = @imagerotate($image, $angle, 0);
            imagedestroy($image);
            if (!$rotated) {
                continue;
            }
            $image = $rotated;
        }

        $image = resizeImageIfNeeded($image, 2200);
        if (defined('IMG_FILTER_GRAYSCALE')) {
            @imagefilter($image, IMG_FILTER_GRAYSCALE);
        }
        if (defined('IMG_FILTER_CONTRAST')) {
            @imagefilter($image, IMG_FILTER_CONTRAST, -25);
        }
        if (defined('IMG_FILTER_BRIGHTNESS')) {
            @imagefilter($image, IMG_FILTER_BRIGHTNESS, 8);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ocr_');
        if ($tmp && @imagejpeg($image, $tmp, 88)) {
            $dataUrl = fileDataUrl($tmp, 'image/jpeg');
            if ($dataUrl !== '') {
                $parts[] = array(
                    'type' => 'input_text',
                    'text' => 'Upload ' . $index . ': prepared OCR variant, rotated ' . $angle . ' degrees, grayscale and contrast enhanced.'
                );
                $parts[] = array(
                    'type' => 'input_image',
                    'image_url' => $dataUrl,
                    'detail' => 'high',
                );
            }
            @unlink($tmp);
        }
        imagedestroy($image);
    }

    imagedestroy($source);
}

function responseOutputText($response)
{
    $text = isset($response['output_text']) ? (string)$response['output_text'] : '';
    if ($text !== '') {
        return $text;
    }

    if (!isset($response['output']) || !is_array($response['output'])) {
        return '';
    }

    foreach ($response['output'] as $item) {
        if (!isset($item['content']) || !is_array($item['content'])) {
            continue;
        }
        foreach ($item['content'] as $content) {
            if ((isset($content['type']) ? $content['type'] : '') === 'output_text') {
                $text .= isset($content['text']) ? (string)$content['text'] : '';
            }
        }
    }
    return $text;
}

function requestStructuredData($apiKey, $model, $contentParts, $schema, &$error)
{
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
        'max_output_tokens' => 2400,
    );

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        $error = 'Не удалось подготовить запрос OCR.';
        return null;
    }

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ),
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 180,
    ));

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $curlError) {
        $error = 'Ошибка соединения с OpenAI API.';
        return null;
    }

    $response = json_decode($raw, true);
    if ($statusCode >= 400) {
        $error = isset($response['error']['message']) ? (string)$response['error']['message'] : 'OpenAI API вернул ошибку.';
        return null;
    }

    $text = responseOutputText(is_array($response) ? $response : array());
    $data = json_decode($text, true);
    if (!is_array($data)) {
        $error = 'ИИ вернул ответ в неожиданном формате.';
        return null;
    }

    return $data;
}

function modelCandidates($preferredModel)
{
    $models = array();
    foreach (array($preferredModel, 'gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-4.1') as $model) {
        $model = trim((string)$model);
        if ($model !== '' && !in_array($model, $models, true)) {
            $models[] = $model;
        }
    }
    return $models;
}

function onlyDigits($value)
{
    return preg_replace('/\D+/', '', (string)$value);
}

function normalizePassportNumberFields(&$data)
{
    $series = isset($data['passport_series']) ? onlyDigits($data['passport_series']) : '';
    $number = isset($data['passport_number']) ? onlyDigits($data['passport_number']) : '';
    $combined = $series . $number;

    if (strlen($series) === 10 && $number === '') {
        $combined = $series;
    }
    if ($series === '' && strlen($number) === 10) {
        $combined = $number;
    }
    if (strlen($combined) === 10) {
        $series = substr($combined, 0, 4);
        $number = substr($combined, 4, 6);
    }

    $data['passport_series'] = $series;
    $data['passport_number'] = $number;
}

function normalizeDateValue($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})[\.\/-](\d{1,2})[\.\/-](\d{2,4})$/', $value, $m)) {
        $year = strlen($m[3]) === 2 ? ('19' . $m[3]) : $m[3];
        return sprintf('%04d-%02d-%02d', (int)$year, (int)$m[2], (int)$m[1]);
    }
    return $value;
}

function cleanRegistrationAddress($value)
{
    $value = str_replace(array("\r", "\n"), ' ', (string)$value);
    $value = preg_replace('/\b\d{1,2}\s*[\.\/-]\s*\d{1,2}\s*[\.\/-]\s*\d{2,4}\b/u', '', $value);
    $value = preg_replace('/\b\d{1,2}\s+\d{1,2}\s+\d{4}\b/u', '', $value);
    $value = preg_replace('/\b\d{4}\s*[\.\/-]\s*\d{1,2}\s*[\.\/-]\s*\d{1,2}\b/u', '', $value);
    $value = preg_replace('/\s{2,}/u', ' ', $value);
    $value = preg_replace('/\s+,/u', ',', $value);
    $value = preg_replace('/,\s*,+/u', ',', $value);
    return trim($value, " \t\n\r\0\x0B,.;");
}

function normalizeExtractedData(&$data)
{
    $fields = array('full_name', 'birth_date', 'birth_place', 'passport_series', 'passport_number', 'issued_by', 'issue_date', 'department_code', 'registration_address');
    foreach ($fields as $field) {
        if (!isset($data[$field]) || !is_scalar($data[$field])) {
            $data[$field] = '';
        } else {
            $data[$field] = trim((string)$data[$field]);
        }
    }

    $data['birth_date'] = normalizeDateValue($data['birth_date']);
    $data['issue_date'] = normalizeDateValue($data['issue_date']);
    $data['registration_address'] = cleanRegistrationAddress($data['registration_address']);
    normalizePassportNumberFields($data);
}

$configPath = __DIR__ . '/../config.php';
$examplePath = __DIR__ . '/../config.example.php';
$config = file_exists($configPath) ? require $configPath : require $examplePath;

$apiKey = trim((string)(isset($config['openai_api_key']) ? $config['openai_api_key'] : ''));
$model = trim((string)(isset($config['openai_model']) ? $config['openai_model'] : 'gpt-5'));
$envApiKey = getenv('OPENAI_API_KEY');
$envModel = getenv('OPENAI_MODEL');
if (is_string($envApiKey) && trim($envApiKey) !== '') {
    $apiKey = trim($envApiKey);
}
if (is_string($envModel) && trim($envModel) !== '') {
    $model = trim($envModel);
}
if ($model === '' || $model === 'gpt-4.1-mini') {
    $model = 'gpt-5';
}
$maxUploadMb = (int)(isset($config['max_upload_mb']) ? $config['max_upload_mb'] : 20);

if ($apiKey === '') {
    failJson(503, 'OpenAI API-ключ не задан в Render Environment.');
}

$uploadedFiles = normalizeUploadedFiles('passport');
if (count($uploadedFiles) === 0) {
    failJson(400, 'Файл не загружен.');
}
$uploadedFiles = array_slice($uploadedFiles, 0, 4);

$allowed = array('image/jpeg', 'image/png', 'image/webp', 'application/pdf');
$contentParts = array();
$contentParts[] = array(
    'type' => 'input_text',
    'text' => <<<'PROMPT'
You are a meticulous OCR and document-understanding system for Russian internal passports.

The user can upload one or several images. A single image can contain several passport fragments on one sheet: identity page, issuing authority page, registration page, copy fragments, or pages placed side by side. Fragments can be rotated 0, 90, 180, or 270 degrees, upside down, very small, photographed at an angle, handwritten, printed, dark, or washed out. Inspect every uploaded file and every prepared variant. Combine visible fields from all fragments that belong to the same passport.

Extract only visible data. Return an empty string for unreadable fields. Do not invent values.

Russian passport rules:
- passport_series is exactly 4 digits. passport_number is exactly 6 digits.
- Series and number are often printed vertically in red on page margins. If 10 consecutive digits are visible, the first 4 are series and the last 6 are number.
- Read issuing authority, issue date, and department code from the issuing page.
- Read full_name, birth_date, birth_place from the identity page near the photo.
- Read registration_address from the residence registration stamp. Registration stamps vary. The address can be handwritten or printed.
- Address must contain only city/locality, street, house, building/corpus/structure if visible, and apartment if visible.
- Apartment is important. It is usually marked by "кв", "кв.", "квартира", "apt", "apartment" and often sits on the same horizontal level as the house number or at the far right of the stamp.
- House is usually marked by "дом", "д.", "house" or appears in a house field near the street.
- Completely ignore registration/propiska dates, expiration dates, and cancellation dates. Dates like 26 04 2002 or 26.04.2002 must never become house or apartment numbers.

Return dates as YYYY-MM-DD. Return department_code as 000-000. Return only the JSON fields required by the schema.
PROMPT
);

$makeVariants = count($uploadedFiles) <= 2;
$index = 1;
foreach ($uploadedFiles as $file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        failJson(400, 'Один из файлов не загрузился.');
    }
    if ((int)$file['size'] > $maxUploadMb * 1024 * 1024) {
        failJson(413, 'Один из файлов слишком большой.');
    }

    $mime = detectMime($file);
    if (!in_array($mime, $allowed, true)) {
        failJson(415, 'Поддерживаются JPG, PNG, WEBP и PDF.');
    }

    addOriginalPart($contentParts, $file, $mime, $index);
    if ($makeVariants) {
        addPreparedImageVariants($contentParts, $file, $mime, $index);
    }
    $index++;
}

$schema = array(
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => array(
        'full_name' => array('type' => 'string'),
        'birth_date' => array('type' => 'string'),
        'birth_place' => array('type' => 'string'),
        'passport_series' => array('type' => 'string'),
        'passport_number' => array('type' => 'string'),
        'issued_by' => array('type' => 'string'),
        'issue_date' => array('type' => 'string'),
        'department_code' => array('type' => 'string'),
        'registration_address' => array('type' => 'string'),
    ),
    'required' => array('full_name', 'birth_date', 'birth_place', 'passport_series', 'passport_number', 'issued_by', 'issue_date', 'department_code', 'registration_address'),
);

$errors = array();
$data = null;
$usedModel = '';
foreach (modelCandidates($model) as $candidate) {
    $error = '';
    $result = requestStructuredData($apiKey, $candidate, $contentParts, $schema, $error);
    if (is_array($result)) {
        $data = $result;
        $usedModel = $candidate;
        break;
    }
    $errors[] = $candidate . ': ' . $error;
}

if (!is_array($data)) {
    failJson(502, 'OCR не смог прочитать документ. ' . implode(' | ', array_slice($errors, 0, 3)));
}

normalizeExtractedData($data);

if ($data['registration_address'] === '' || mb_strlen($data['registration_address'], 'UTF-8') < 8) {
    $addressParts = $contentParts;
    $addressParts[0] = array(
        'type' => 'input_text',
        'text' => 'Read ONLY the Russian passport residence registration stamp. The page may be upside down or rotated. Extract city/locality, street, house, building/corpus/structure and apartment. Apartment is marked by кв, кв., квартира, apt or apartment. Ignore all dates completely. Return only registration_address.'
    );
    $addressSchema = array(
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => array('registration_address' => array('type' => 'string')),
        'required' => array('registration_address'),
    );
    foreach (modelCandidates($model) as $candidate) {
        $error = '';
        $addressData = requestStructuredData($apiKey, $candidate, $addressParts, $addressSchema, $error);
        if (is_array($addressData) && isset($addressData['registration_address'])) {
            $address = cleanRegistrationAddress($addressData['registration_address']);
            if ($address !== '') {
                $data['registration_address'] = $address;
                break;
            }
        }
    }
}

sendJson(array('ok' => true, 'data' => $data, 'model' => $usedModel), 200);
