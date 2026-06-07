FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 libcurl4-openssl-dev libzip-dev libxml2-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libonig-dev zip unzip \
    && ln -sf /usr/bin/python3 /usr/local/bin/python \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install curl zip gd mbstring dom \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY public_html/ /var/www/html/

RUN python3 - <<'PY'
import base64
import zipfile
from pathlib import Path
p = Path('/var/www/html/contract_template.docx')
with zipfile.ZipFile(p, 'r') as z:
    z.read('word/document.xml')
Path('/var/www/html/contract_template.base64.txt').write_text(
    base64.b64encode(p.read_bytes()).decode('ascii'),
    encoding='ascii'
)
PY

RUN mkdir -p /var/www/html/uploads /var/www/html/generated \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/generated \
    && printf "upload_max_filesize=20M\npost_max_size=24M\nmemory_limit=256M\nmax_execution_time=180\n" > /usr/local/etc/php/conf.d/app.ini

EXPOSE 10000

CMD printf "%s\n" "<?php" "return array(" "    'openai_api_key' => getenv('OPENAI_API_KEY') ?: ''," "    'openai_model' => getenv('OPENAI_MODEL') ?: 'gpt-5.2'," "    'max_upload_mb' => 20," ");" > /var/www/html/config.php \
    && chown www-data:www-data /var/www/html/config.php \
    && sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf \
    && sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground
