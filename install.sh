#!/bin/bash
set -e

# Цвета оформления
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}=====================================================${NC}"
echo -e "${CYAN}      Автоматическая установка и настройка apiNode   ${NC}"
echo -e "${BLUE}=====================================================${NC}"

# 1. Проверка прав root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Скрипт должен быть запущен с правами root (sudo).${NC}"
    exit 1
fi

# 2. Определение директории проекта
if [ -f "$PWD/docker-compose.yaml" ]; then
    PROJECT_DIR="$PWD"
elif [ -d "/home/iliy/apiNode" ]; then
    PROJECT_DIR="/home/iliy/apiNode"
else
    PROJECT_DIR="/home/iliy/apiNode"
fi

if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${CYAN}Клонируем репозиторий apiNode в $PROJECT_DIR...${NC}"
    git clone https://github.com/iliy-git/apiNode.git "$PROJECT_DIR"
else
    echo -e "${CYAN}Обновляем репозиторий в $PROJECT_DIR...${NC}"
    cd "$PROJECT_DIR"
    if [ -d ".git" ]; then
        git pull origin main 2>/dev/null || true
    fi
fi
cd "$PROJECT_DIR"

# Гарантия наличия apinode.sh
if [ ! -f "$PROJECT_DIR/apinode.sh" ]; then
    curl -sSL https://raw.githubusercontent.com/iliy-git/apiNode/main/apinode.sh -o "$PROJECT_DIR/apinode.sh"
fi

# 3. Выбор порта
DEFAULT_PORT="11223"
if [ -t 0 ]; then
    read -r -p "Введите порт для API node [по умолчанию $DEFAULT_PORT]: " USER_PORT
    API_PORT="${USER_PORT:-$DEFAULT_PORT}"
else
    API_PORT="$DEFAULT_PORT"
fi
echo -e "${GREEN}Выбран порт: ${API_PORT}${NC}"

# 4. Обновление пакетов и установка зависимостей
echo -e "\n${YELLOW}[1/7] Проверка и установка пакетов...${NC}"
apt-get update -y
apt-get install -y curl git openssl ca-certificates

# 5. Проверка и установка Docker
echo -e "\n${YELLOW}[2/7] Проверка Docker и Docker Compose...${NC}"
if ! command -v docker &> /dev/null; then
    echo -e "${CYAN}Устанавливаем Docker...${NC}"
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
else
    echo -e "${GREEN}Docker уже установлен.${NC}"
fi

if ! docker compose version &> /dev/null; then
    echo -e "${CYAN}Устанавливаем docker compose plugin...${NC}"
    apt-get install -y docker-compose-plugin
fi

# 6. Проверка и симлинк бинарника Xray для работы API без рестарта ядра
echo -e "\n${YELLOW}[3/7] Настройка бинарника Xray для прямого API...${NC}"
if [ -f "/usr/local/x-ui/bin/xray-linux-amd64" ]; then
    ln -sf /usr/local/x-ui/bin/xray-linux-amd64 /usr/local/bin/xray
    chmod +x /usr/local/bin/xray
    echo -e "${GREEN}Симлинк Xray (/usr/local/bin/xray) успешно создан.${NC}"
elif [ -f "/usr/local/bin/xray" ]; then
    echo -e "${GREEN}Бинарник /usr/local/bin/xray уже на месте.${NC}"
else
    echo -e "${YELLOW}[ВНИМАНИЕ] Бинарник Xray не найден в /usr/local/x-ui/bin/. Убедитесь, что Xray или 3X-UI установлены на сервере.${NC}"
fi

# 7. Генерация самоподписанных SSL-сертификатов
echo -e "\n${YELLOW}[4/7] Проверка SSL-сертификатов Nginx...${NC}"
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

# 8. Настройка порта в конфиге Nginx и открытие в файрволе
echo -e "\n${YELLOW}[5/7] Настройка порта ${API_PORT} и открытие в файрволе...${NC}"
sed -i "s/listen [0-9]\+ ssl;/listen ${API_PORT} ssl;/" "$PROJECT_DIR/nginx/conf.d/default.conf"

# Открытие порта
if command -v ufw > /dev/null 2>&1; then
    if ufw status | grep -q "Status: active"; then
        ufw allow "${API_PORT}/tcp" > /dev/null 2>&1 || true
        echo -e "${GREEN}Порт ${API_PORT} открыт в UFW.${NC}"
    fi
fi
if command -v iptables > /dev/null 2>&1; then
    iptables -I INPUT -p tcp --dport "${API_PORT}" -j ACCEPT > /dev/null 2>&1 || true
fi
if command -v firewall-cmd > /dev/null 2>&1; then
    firewall-cmd --add-port="${API_PORT}/tcp" --permanent > /dev/null 2>&1 || true
    firewall-cmd --reload > /dev/null 2>&1 || true
fi

# 9. Установка команды apinode в систему
chmod +x "$PROJECT_DIR/apinode.sh"
ln -sf "$PROJECT_DIR/apinode.sh" /usr/local/bin/apinode
ln -sf "$PROJECT_DIR/apinode.sh" /usr/bin/apinode

# 10. Запуск контейнеров Docker
echo -e "\n${YELLOW}[6/7] Запуск контейнеров apiNode...${NC}"
docker compose down 2>/dev/null || true
docker compose up -d

# 11. Проверка / генерация API-ключа
echo -e "\n${YELLOW}[7/7] Проверка API-ключа...${NC}"
if [ ! -s "$PROJECT_DIR/.key" ]; then
    echo -e "${CYAN}Генерируем новый API-ключ...${NC}"
    openssl rand -hex 16 > "$PROJECT_DIR/.key"
fi
API_KEY=$(cat "$PROJECT_DIR/.key" | tr -d '\r\n ')

# Тест пинга
sleep 2
PING_STATUS=$(curl -k -s -H "X-API-KEY: $API_KEY" "https://127.0.0.1:${API_PORT}/ping" 2>/dev/null || true)
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
echo -e "  Порт API   : ${GREEN}${API_PORT}${NC}"
echo -e "  API Ключ   : ${GREEN}${API_KEY}${NC}"
echo -e "${BLUE}─────────────────────────────────────────────────────${NC}"
echo -e "  ${YELLOW}Управление нодой:${NC} введите команду ${GREEN}apinode${NC} в терминале"
echo -e "${BLUE}=====================================================${NC}\n"
