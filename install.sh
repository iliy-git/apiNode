#!/bin/bash
set -e

# Цвета для красивого вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${BLUE}=====================================================${NC}"
echo -e "${CYAN}      Автоматическая установка и настройка apiNode   ${NC}"
echo -e "${BLUE}=====================================================${NC}"

# 1. Проверка прав root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Скрипт должен быть запущен с правами root (sudo).${NC}"
    exit 1
fi

# 2. Обновление пакетов и установка базовых утилит
echo -e "\n${YELLOW}[1/8] Проверка и установка базовых пакетов...${NC}"
apt-get update -y
apt-get install -y curl git openssl ca-certificates

# 3. Проверка и установка Docker
echo -e "\n${YELLOW}[2/8] Проверка Docker и Docker Compose...${NC}"
if ! command -v docker &> /dev/null; then
    echo -e "${CYAN}Docker не найден. Устанавливаем Docker...${NC}"
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
else
    echo -e "${GREEN}Docker уже установлен.${NC}"
fi

# Проверка docker compose
if ! docker compose version &> /dev/null; then
    echo -e "${CYAN}Устанавливаем docker compose plugin...${NC}"
    apt-get install -y docker-compose-plugin
fi

# 4. Проверка и симлинк бинарника Xray для работы API без рестарта
echo -e "\n${YELLOW}[3/8] Настройка бинарника Xray для API...${NC}"
if [ -f "/usr/local/x-ui/bin/xray-linux-amd64" ]; then
    ln -sf /usr/local/x-ui/bin/xray-linux-amd64 /usr/local/bin/xray
    chmod +x /usr/local/bin/xray
    echo -e "${GREEN}Симлинк Xray (/usr/local/bin/xray) успешно создан.${NC}"
elif [ -f "/usr/local/bin/xray" ]; then
    echo -e "${GREEN}Бинарник /usr/local/bin/xray уже на месте.${NC}"
else
    echo -e "${RED}[ВНИМАНИЕ] Бинарник Xray не найден в /usr/local/x-ui/bin/. Убедитесь, что Xray или 3X-UI установлены.${NC}"
fi

# 5. Определение папки проекта
echo -e "\n${YELLOW}[4/8] Подготовка директории apiNode...${NC}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
TARGET_DIR="/home/iliy/apiNode"

if [ -f "$SCRIPT_DIR/docker-compose.yaml" ]; then
    PROJECT_DIR="$SCRIPT_DIR"
else
    if [ ! -d "$TARGET_DIR" ]; then
        echo -e "${CYAN}Клонируем репозиторий apiNode...${NC}"
        git clone https://github.com/iliy-git/apiNode.git "$TARGET_DIR"
    fi
    PROJECT_DIR="$TARGET_DIR"
fi
cd "$PROJECT_DIR"
echo -e "${GREEN}Рабочая директория: $PROJECT_DIR${NC}"

# 6. Генерация самоподписанных SSL-сертификатов
echo -e "\n${YELLOW}[5/8] Проверка SSL-сертификатов для Nginx...${NC}"
mkdir -p /etc/ssl/private /etc/ssl/certs
if [ ! -f "/etc/ssl/private/node-selfsigned.key" ] || [ ! -f "/etc/ssl/certs/node-selfsigned.crt" ]; then
    echo -e "${CYAN}Генерируем SSL-сертификаты на 10 лет...${NC}"
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
      -keyout /etc/ssl/private/node-selfsigned.key \
      -out /etc/ssl/certs/node-selfsigned.crt \
      -subj "/C=RU/ST=Rostov/L=Rostov-on-Don/O=IT/CN=nodehost"
    chmod 600 /etc/ssl/private/node-selfsigned.key
    chmod 644 /etc/ssl/certs/node-selfsigned.crt
    echo -e "${GREEN}SSL-сертификаты созданы.${NC}"
else
    echo -e "${GREEN}SSL-сертификаты уже существуют.${NC}"
fi

# 7. Настройка аварийного сервиса перезапуска x-ui (systemd path watcher)
echo -e "\n${YELLOW}[6/8] Настройка службы отслеживания перезапуска x-ui...${NC}"
mkdir -p /etc/x-ui
cat << 'SVCEOF' > /etc/systemd/system/x-ui-restart.service
[Unit]
Description=Trigger X-UI Restart

[Service]
Type=oneshot
ExecStart=/bin/systemctl restart x-ui
SVCEOF

cat << 'PATHEOF' > /etc/systemd/system/x-ui-restart.path
[Unit]
Description=Watch /etc/x-ui/restart.flag for changes

[Path]
PathModified=/etc/x-ui/restart.flag

[Install]
WantedBy=multi-user.target
PATHEOF

systemctl daemon-reload
systemctl enable --now x-ui-restart.path 2>/dev/null || true
echo -e "${GREEN}Службы x-ui-restart активированы.${NC}"

# 8. Запуск контейнеров Docker
echo -e "\n${YELLOW}[7/8] Запуск контейнеров apiNode...${NC}"
docker compose down 2>/dev/null || true
docker compose up -d

# 9. Генерация API-ключа
echo -e "\n${YELLOW}[8/8] Проверка API-ключа...${NC}"
if [ ! -s "$PROJECT_DIR/.key" ]; then
    echo -e "${CYAN}Генерируем новый API-ключ...${NC}"
    docker compose exec app php key.php gen > /dev/null 2>&1 || openssl rand -hex 16 > "$PROJECT_DIR/.key"
fi
API_KEY=$(cat "$PROJECT_DIR/.key" | tr -d '\r\n ')

# Проверка работоспособности через локальный ping
sleep 2
PING_STATUS=$(curl -k -s -H "X-API-KEY: $API_KEY" https://127.0.0.1:11223/ping 2>/dev/null || true)

# Определение внешнего IP
SERVER_IP=$(curl -s4 ifconfig.me 2>/dev/null || ip route get 1.1.1.1 2>/dev/null | awk '{print $7}')

echo -e "\n${BLUE}=====================================================${NC}"
if [[ "$PING_STATUS" == *"\"status\":\"ok\""* ]]; then
    echo -e "${GREEN}   ✅ apiNode успешно установлен и запущен!${NC}"
else
    echo -e "${YELLOW}   ⚠️ Контейнеры запущены, но проверка пинга вернула:${NC} $PING_STATUS"
fi
echo -e "${BLUE}=====================================================${NC}"
echo -e "${CYAN}Данные для добавления ноды в панель (LWproject):${NC}"
echo -e "  IP сервера : ${GREEN}${SERVER_IP}${NC}"
echo -e "  Порт API   : ${GREEN}11223${NC}"
echo -e "  API Ключ   : ${GREEN}${API_KEY}${NC}"
echo -e "${BLUE}=====================================================${NC}\n"
