FROM php:8.2-apache

# cURL 확장 설치 (Scrutiny API 호출용)
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 소스 코드 복사 (index.php로 이름 변경하여 루트 접속 가능하게 설정)
COPY disk_info.php /var/www/html/index.php

# Apache 포트 설정 (기본 80)
EXPOSE 80
