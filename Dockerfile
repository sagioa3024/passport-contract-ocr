FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libzip-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev python3 unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install curl zip gd \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY .github/workflows/apply-ocr-patch.yml /tmp/apply-ocr-patch.yml
COPY public_html/ /var/www/html/

RUN python3 - <<'PY'
from pathlib import Path
workflow = Path('/tmp/apply-ocr-patch.yml').read_text(encoding='utf-8')
start = workflow.index("          python3 <<'PY'\n") + len("          python3 <<'PY'\n")
end = workflow.index("\n          PY", start)
lines = []
for line in workflow[start:end].splitlines():
    lines.append(line[10:] if line.startswith('          ') else line)
code = '\n'.join(lines)
code = code.replace("Path('public_html/api/recognize.php')", "Path('/var/www/html/api/recognize.php')")
code = code.replace("Path('public_html/config.example.php')", "Path('/var/www/html/config.example.php')")
exec(compile(code, 'apply-ocr-patch.yml', 'exec'), {})
PY

RUN mkdir -p /var/www/html/uploads /var/www/html/generated \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/generated \
    && printf "upload_max_filesize=20M\npost_max_size=24M\nmemory_limit=256M\nmax_execution_time=120\n" > /usr/local/etc/php/conf.d/app.ini

EXPOSE 10000

CMD printf "%s\n" "<?php" "return array(" "    'openai_api_key' => getenv('OPENAI_API_KEY') ?: ''," "    'openai_model' => getenv('OPENAI_MODEL') ?: 'gpt-5.2'," "    'max_upload_mb' => 20," ");" > /var/www/html/config.php \
    && chown www-data:www-data /var/www/html/config.php \
    && sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf \
    && sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground
