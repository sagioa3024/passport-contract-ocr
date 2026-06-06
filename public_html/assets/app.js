const fileInput = document.querySelector('#passportFile');
const previewList = document.querySelector('#previewList');
const recognizeBtn = document.querySelector('#recognizeBtn');
const recognizeMessage = document.querySelector('#recognizeMessage');
const clearBtn = document.querySelector('#clearBtn');
const form = document.querySelector('#contractForm');
const aiStatus = document.querySelector('#aiStatus');
let selectedFiles = [];

const fieldMap = {
  full_name: 'full_name',
  birth_date: 'birth_date',
  birth_place: 'birth_place',
  passport_series: 'passport_series',
  passport_number: 'passport_number',
  issued_by: 'issued_by',
  issue_date: 'issue_date',
  department_code: 'department_code',
  registration_address: 'registration_address'
};

fileInput.addEventListener('change', () => {
  const incomingFiles = [...fileInput.files];
  selectedFiles = mergeFiles(selectedFiles, incomingFiles);
  syncInputFiles();
  renderSelectedFiles();
});

function mergeFiles(currentFiles, newFiles) {
  const files = [...currentFiles];
  newFiles.forEach((file) => {
    const exists = files.some((item) => (
      item.name === file.name &&
      item.size === file.size &&
      item.lastModified === file.lastModified
    ));
    if (!exists) files.push(file);
  });
  return files;
}

function syncInputFiles() {
  if (typeof DataTransfer === 'undefined') return;

  const transfer = new DataTransfer();
  selectedFiles.forEach((file) => transfer.items.add(file));
  fileInput.files = transfer.files;
}

function renderSelectedFiles() {
  const files = selectedFiles;
  recognizeBtn.disabled = files.length === 0;
  recognizeMessage.textContent = files.length
    ? `Выбрано файлов: ${files.length}. Можно распознать или заполнить поля вручную.`
    : 'Можно заполнить форму вручную и сразу создать договор.';

  previewList.innerHTML = '';
  const imageFiles = files.filter((file) => file.type.startsWith('image/'));
  if (!imageFiles.length) {
    files.forEach((file) => {
      previewList.appendChild(createFileCard(file));
    });
    previewList.hidden = files.length === 0;
    return;
  }

  files.forEach((file) => {
    if (file.type.startsWith('image/')) {
      previewList.appendChild(createPreviewCard(file));
    } else {
      previewList.appendChild(createFileCard(file));
    }
  });
  previewList.hidden = false;
}

function createPreviewCard(file) {
  const card = document.createElement('div');
  card.className = 'preview-card';

  const image = document.createElement('img');
  image.className = 'preview';
  image.alt = file.name || 'Предпросмотр паспорта';
  image.src = URL.createObjectURL(file);

  const meta = document.createElement('div');
  meta.className = 'preview-meta';

  const name = document.createElement('span');
  name.className = 'preview-name';
  name.textContent = file.name || 'Изображение';

  meta.append(name);
  card.append(image, meta);
  return card;
}

function createFileCard(file) {
  const card = document.createElement('div');
  card.className = 'file-card';

  const name = document.createElement('span');
  name.className = 'preview-name';
  name.textContent = file.name || 'Файл';

  card.append(name);
  return card;
}

function normalizeDate(value) {
  const text = String(value || '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;

  const match = text.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})$/);
  if (!match) return text;

  const day = match[1].padStart(2, '0');
  const month = match[2].padStart(2, '0');
  let year = match[3];
  if (year.length === 2) year = Number(year) > 30 ? `19${year}` : `20${year}`;
  return `${year}-${month}-${day}`;
}

recognizeBtn.addEventListener('click', async () => {
  const files = selectedFiles.length ? selectedFiles : [...fileInput.files];
  if (!files.length) return;

  const data = new FormData();
  files.forEach((file) => data.append('passport[]', file));
  recognizeBtn.disabled = true;
  recognizeMessage.textContent = 'Распознаю данные...';

  try {
    const response = await fetch('api/recognize.php', {
      method: 'POST',
      body: data
    });

    const result = await response.json();
    if (!response.ok || !result.ok) {
      throw new Error(result.error || 'Не удалось распознать документ');
    }

    Object.entries(fieldMap).forEach(([apiKey, inputName]) => {
      const input = form.elements[inputName];
      if (input && result.data && result.data[apiKey]) {
        input.value = input.type === 'date' ? normalizeDate(result.data[apiKey]) : result.data[apiKey];
      }
    });

    if (aiStatus) {
      aiStatus.textContent = 'ИИ распознавание подключено';
    }
    recognizeMessage.textContent = 'Готово. Проверьте поля перед созданием договора.';
  } catch (error) {
    recognizeMessage.textContent = error.message;
  } finally {
    recognizeBtn.disabled = false;
  }
});

clearBtn.addEventListener('click', () => {
  selectedFiles = [];
  fileInput.value = '';
  renderSelectedFiles();
  [...form.elements].forEach((element) => {
    if (element.name && element.type !== 'checkbox' && element.name !== 'contract_date') {
      element.value = '';
    }
    if (element.type === 'checkbox') {
      element.checked = false;
    }
  });
});
