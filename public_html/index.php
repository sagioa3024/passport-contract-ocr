<?php
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Договор по паспорту</title>
  <link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body>
  <main class="app-shell">
    <section class="workspace">
      <header class="topbar">
        <div>
          <p class="eyebrow">MVP</p>
          <h1>Договор по данным паспорта</h1>
        </div>
        <span class="status" id="aiStatus">ИИ выключен, пока не задан API-ключ</span>
      </header>

      <div class="notice">
        Загружайте документ только с согласия человека. Фото используется для распознавания и не должно храниться дольше, чем нужно для создания договора.
      </div>

      <div class="layout">
        <section class="panel upload-panel">
          <h2>Паспорт</h2>
          <label class="dropzone" for="passportFile">
            <input id="passportFile" type="file" accept="image/*,.pdf" multiple>
            <span class="drop-title">Выберите лицо и прописку</span>
            <span class="drop-subtitle">Можно выбрать 2 картинки сразу или добавить их по очереди</span>
          </label>
          <div id="previewList" class="preview-list" hidden></div>
          <button class="button secondary" id="recognizeBtn" type="button" disabled>Распознать данные</button>
          <p class="small" id="recognizeMessage">Можно заполнить форму вручную и сразу создать договор.</p>
        </section>

        <section class="panel">
          <form id="contractForm" action="generate.php" method="post">
            <div class="form-head">
              <h2>Проверка данных</h2>
              <button class="button ghost" type="button" id="clearBtn">Очистить</button>
            </div>

            <label>
              ФИО
              <input name="full_name" autocomplete="name" required>
            </label>

            <div class="grid two">
              <label>
                Дата рождения
                <input name="birth_date" type="date">
              </label>
              <label>
                Место рождения
                <input name="birth_place">
              </label>
            </div>

            <div class="grid three">
              <label>
                Серия
                <input name="passport_series" inputmode="numeric" maxlength="4">
              </label>
              <label>
                Номер
                <input name="passport_number" inputmode="numeric" maxlength="6">
              </label>
              <label>
                Код подразделения
                <input name="department_code" placeholder="000-000">
              </label>
            </div>

            <label>
              Кем выдан
              <textarea name="issued_by" rows="3"></textarea>
            </label>

            <div class="grid two">
              <label>
                Дата выдачи
                <input name="issue_date" type="date">
              </label>
              <label>
                Дата договора
                <input name="contract_date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES) ?>">
              </label>
            </div>

            <label>
              Адрес регистрации: город, улица, дом, квартира
              <textarea name="registration_address" rows="3"></textarea>
            </label>

            <label>
              Телефон для договора
              <input name="phone" type="tel" placeholder="+7">
            </label>

            <div class="grid two">
              <label>
                Номер договора
                <input name="contract_number" value="1">
              </label>
              <label>
                Город
                <input name="city" value="Москва">
              </label>
            </div>

            <label class="checkbox">
              <input name="consent" type="checkbox" required>
              <span>Есть согласие на обработку персональных данных и данные проверены.</span>
            </label>

            <button class="button primary" type="submit">Сформировать договор DOCX</button>
          </form>
        </section>
      </div>
    </section>
  </main>

  <script src="assets/app.js?v=4"></script>
</body>
</html>
