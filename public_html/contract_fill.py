#!/usr/bin/env python3
import base64
import io
import json
import sys
import zipfile
from pathlib import Path
import xml.etree.ElementTree as ET

W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
XML_SPACE = '{http://www.w3.org/XML/1998/namespace}space'
ET.register_namespace('w', W_NS)

COMMON_NAMESPACES = {
    'r': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'wp': 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
    'a': 'http://schemas.openxmlformats.org/drawingml/2006/main',
    'pic': 'http://schemas.openxmlformats.org/drawingml/2006/picture',
}
for prefix, uri in COMMON_NAMESPACES.items():
    ET.register_namespace(prefix, uri)


def paragraph_text(paragraph):
    return ''.join(node.text or '' for node in paragraph.findall('.//{%s}t' % W_NS))


def replace_paragraph_text(paragraph, text):
    nodes = paragraph.findall('.//{%s}t' % W_NS)
    if not nodes:
        return
    nodes[0].text = text
    nodes[0].set(XML_SPACE, 'preserve')
    for node in nodes[1:]:
        node.text = ''


def has_all(text, needles):
    return all(needle in text for needle in needles)


def fill_document_xml(xml_bytes, data):
    root = ET.fromstring(xml_bytes)
    replacements = {'date': 0, 'customer': 0, 'signature': 0}

    for paragraph in root.findall('.//{%s}p' % W_NS):
        text = paragraph_text(paragraph)

        if has_all(text, ['г. Новороссийск', '10 января 2026']) or '10 января 2026г' in text:
            replace_paragraph_text(paragraph, 'г. %s\t\t\t\t\t%s г.' % (data['city'], data['contract_date']))
            replacements['date'] += 1
            continue

        if has_all(text, ['гр. РФ', 'Петров Петр Петрович']) or has_all(text, ['паспорт серия', 'проживающий']):
            replace_paragraph_text(paragraph, data['customer_paragraph'])
            replacements['customer'] += 1
            continue

        if 'Заказчик' in text and 'Слушатель' in text:
            signature_line = 'Заказчик' + chr(92) + 'Слушатель\t    __________ /%s/' % data['signature']
            replace_paragraph_text(paragraph, signature_line)
            replacements['signature'] += 1
            continue

    if replacements['date'] == 0:
        raise RuntimeError('Не найдено место даты и города в шаблоне договора.')
    if replacements['customer'] == 0:
        raise RuntimeError('Не найден абзац с паспортными данными в шаблоне договора.')

    return ET.tostring(root, encoding='utf-8', xml_declaration=True)


def load_template_bytes(template_path):
    candidates = []
    base64_path = template_path.with_name('contract_template.base64.txt')

    if base64_path.exists():
        raw = ''.join(base64_path.read_text(encoding='utf-8', errors='ignore').split())
        try:
            decoded = base64.b64decode(raw, validate=True)
            candidates.append(('base64', decoded))
        except Exception:
            pass

    if template_path.exists():
        candidates.append(('local', template_path.read_bytes()))

    errors = []
    for label, data in candidates:
        if len(data) < 1000 or not data.startswith(b'PK'):
            errors.append('%s: not a docx zip' % label)
            continue
        try:
            with zipfile.ZipFile(io.BytesIO(data), 'r') as zf:
                zf.read('word/document.xml')
            return data
        except Exception as exc:
            errors.append('%s: %s' % (label, exc))

    raise RuntimeError('No readable template. ' + ' | '.join(errors))


def build_docx(template_bytes, output_path, data):
    source_buffer = io.BytesIO(template_bytes)
    with zipfile.ZipFile(source_buffer, 'r') as source:
        document_xml = source.read('word/document.xml')
        filled_xml = fill_document_xml(document_xml, data)
        with zipfile.ZipFile(output_path, 'w', zipfile.ZIP_DEFLATED) as target:
            seen = set()
            for info in source.infolist():
                if info.filename in seen:
                    continue
                seen.add(info.filename)
                content = filled_xml if info.filename == 'word/document.xml' else source.read(info.filename)
                new_info = zipfile.ZipInfo(info.filename, date_time=info.date_time)
                new_info.compress_type = zipfile.ZIP_DEFLATED
                new_info.external_attr = info.external_attr
                target.writestr(new_info, content)

    with zipfile.ZipFile(output_path, 'r') as check:
        bad = check.testzip()
        if bad:
            raise RuntimeError('Generated DOCX failed zip test at %s' % bad)
        check.read('word/document.xml')


def main():
    payload = json.load(sys.stdin)
    template_path = Path(payload['template_path'])
    output_path = Path(payload['output_path'])
    data = payload['data']
    output_path.parent.mkdir(parents=True, exist_ok=True)
    template_bytes = load_template_bytes(template_path)
    build_docx(template_bytes, output_path, data)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
