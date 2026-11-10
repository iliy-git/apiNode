#!/bin/bash

# Цвета оформления
red='\033[0;31m'
green='\033[0;32m'
blue='\033[0;34m'
yellow='\033[0;33m'
cyan='\033[0;36m'
plain='\033[0m'

# Определение директории проекта через разыменование симлинка
SOURCE="${BASH_SOURCE[0]}"
while [ -h "$SOURCE" ]; do
    DIR="$(cd -P "$(dirname "$SOURCE")" >/dev/null 2>&1 && pwd)"
    SOURCE="$(readlink "$SOURCE")"
    [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
done
TARGET_DIR="$(cd -P "$(dirname "$SOURCE")" >/dev/null 2>&1 && pwd)"

if [ ! -f "$TARGET_DIR/docker-compose.yaml" ]; then
    if [ -f "/home/iliy/apiNode/docker-compose.yaml" ]; then
        TARGET_DIR="/home/iliy/apiNode"
    elif [ -f "$PWD/docker-compose.yaml" ]; then
        TARGET_DIR="$PWD"
    fi
fi
cd "$TARGET_DIR" || exit 1

NGINX_CONF="$TARGET_DIR/nginx/conf.d/default.conf"
KEY_FILE="$TARGET_DIR/.key"

# Функция получения текущего порта
get_current_port() {
    if [ -f "$NGINX_CONF" ]; then
        awk '/listen [0-9]+ ssl;/ {print $2}' "$NGINX_CONF" | head -n 1
    else
        echo "11223"
    fi
}

# Функция получения текущего ключа
get_current_key() {
    if [ -f "$KEY_FILE" ]; then
        tr -d '\r\n ' < "$KEY_FILE"
    else
        echo "Не найден"
    fi
}

# Функция получения внешних IP
get_external_ips() {
    ip -4 addr show scope global | awk '/inet / {print $2}' | cut -d/ -f1
}

# Функция открытия порта в файрволе
open_port() {
    local port="$1"
    if command -v ufw > /dev/null 2>&1; then
        if ufw status | grep -q "Status: active"; then
            ufw allow "$port/tcp" > /dev/null 2>&1
            echo -e "${green}Порт $port открыт в UFW.${plain}"
        fi
    fi
    if command -v iptables > /dev/null 2>&1; then
        iptables -I INPUT -p tcp --dport "$port" -j ACCEPT > /dev/null 2>&1 || true
    fi
    if command -v firewall-cmd > /dev/null 2>&1; then
        firewall-cmd --add-port="$port/tcp" --permanent > /dev/null 2>&1 || true
        firewall-cmd --reload > /dev/null 2>&1 || true
    fi
}

# Проверка статуса
check_status() {
    local port
    port=$(get_current_port)
    local key
    key=$(get_current_key)
    local ext_ip
    ext_ip=$(curl -s4 ifconfig.me 2>/dev/null || ip route get 1.1.1.1 2>/dev/null | awk '{print $7}')

    echo -e "\n${blue}══════════════════════════════════════════════════════${plain}"
    echo -e "           ${cyan}Статус службы apiNode${plain}"
    echo -e "${blue}══════════════════════════════════════════════════════${plain}"

    local app_running
    app_running=$(docker ps -q -f name=x-ui-api-mini)
    local nginx_running
    nginx_running=$(docker ps -q -f name=vpn_nginx_ssl)

    if [ -n "$app_running" ] && [ -n "$nginx_running" ]; then
        echo -e "Состояние      : ${green}● Запущен и работает${plain}"
    else
        echo -e "Состояние      : ${red}● Остановлен или сбой${plain}"
    fi

    echo -e "Внешний IP     : ${green}${ext_ip}${plain}"
    echo -e "Порт API       : ${green}${port}${plain}"
    echo -e "API Ключ       : ${green}${key}${plain}"

    # Локальный пинг
    local ping_res
    ping_res=$(curl -k -s -H "X-API-KEY: $key" "https://127.0.0.1:${port}/ping" 2>/dev/null || true)
    if [[ "$ping_res" == *"\"status\":\"ok\""* ]]; then
        echo -e "Проверка связи : ${green}OK (Успешно авторизован)${plain}"
    else
        echo -e "Проверка связи : ${red}Ошибка ответа /ping (${ping_res})${plain}"
    fi
    echo -e "${blue}══════════════════════════════════════════════════════${plain}\n"
}

# Изменение порта
change_port() {
    local current_port
    current_port=$(get_current_port)
    echo -e "\nТекущий порт API: ${green}${current_port}${plain}"
    read -r -p "Введите новый порт (1024-65535): " new_port

    if ! [[ "$new_port" =~ ^[0-9]+$ ]] || [ "$new_port" -lt 1 ] || [ "$new_port" -gt 65535 ]; then
        echo -e "${red}Неверный номер порта.${plain}"
        return 1
    fi

    sed -i "s/listen [0-9]\+ ssl;/listen ${new_port} ssl;/" "$NGINX_CONF"
    open_port "$new_port"

    echo -e "${yellow}Перезапуск nginx...${plain}"
    docker compose restart nginx > /dev/null 2>&1
    echo -e "${green}Порт успешно изменен на ${new_port} и открыт в фаерволе!${plain}"
}

# Управление API-ключом
manage_key() {
    echo -e "\n1. Показать текущий ключ"
    echo -e "2. Сгенерировать новый случайный ключ"
    echo -e "3. Задать свой ключ вручную"
    read -r -p "Выберите действие [1-3]: " choice
    case "$choice" in
        1)
            echo -e "🔑 Текущий API-ключ: ${green}$(get_current_key)${plain}"
            ;;
        2)
            local new_key
            new_key=$(openssl rand -hex 16)
            echo "$new_key" > "$KEY_FILE"
            echo -e "${green}✅ Новый API-ключ успешно установлен: ${new_key}${plain}"
            ;;
        3)
            read -r -p "Введите желаемый ключ: " custom_key
            if [ -n "$custom_key" ]; then
                echo "$custom_key" > "$KEY_FILE"
                echo -e "${green}✅ Ключ успешно обновлен: ${custom_key}${plain}"
            else
                echo -e "${red}Ключ не может быть пустым.${plain}"
            fi
            ;;
        *)
            echo -e "${red}Неверный выбор.${plain}"
            ;;
    esac
}

# Синхронизация клиентов в Xray
sync_clients() {
    local port
    port=$(get_current_port)
    local key
    key=$(get_current_key)
    echo -e "${yellow}Отправка запроса на синхронизацию активных клиентов в память Xray...${plain}"
    local res
    res=$(curl -k -s -H "X-API-KEY: $key" "https://127.0.0.1:${port}/xray/sync" 2>/dev/null || true)
    echo -e "Ответ ядра: ${green}${res}${plain}"
}

# Список всех IP на сервере
show_ips() {
    echo -e "\n${cyan}Список доступных IP-адресов на сервере:${plain}"
    echo -e "──────────────────────────────────────────────"
    echo -e "Публичный IPv4 : ${green}$(curl -s4 ifconfig.me 2>/dev/null || echo 'не определен')${plain}"
    local pub_v6
    pub_v6=$(curl -s6 ifconfig.me 2>/dev/null || echo '')
    if [ -n "$pub_v6" ]; then
        echo -e "Публичный IPv6 : ${green}${pub_v6}${plain}"
    fi
    echo -e "\nЛокальные сетевые интерфейсы:"
    ip -o addr show scope global | awk '{print "  " $2 " -> " $4}'
    echo -e "──────────────────────────────────────────────"
}

# Обновление проекта
update_project() {
    echo -e "${yellow}Получение обновлений из репозитория GitHub...${plain}"
    cd "$TARGET_DIR" || exit 1
    git pull origin main
    echo -e "${yellow}Перезапуск контейнеров...${plain}"
    docker compose down 2>/dev/null || true
    docker compose up -d
    echo -e "${green}apiNode успешно обновлен до последней версии!${plain}"
}

# Меню
show_menu() {
    clear
    local port
    port=$(get_current_port)
    local status_label
    if [ -n "$(docker ps -q -f name=x-ui-api-mini)" ] && [ -n "$(docker ps -q -f name=vpn_nginx_ssl)" ]; then
        status_label="${green}запущен${plain}"
    else
        status_label="${red}остановлен${plain}"
    fi

    echo -e "
╔────────────────────────────────────────────────╗
│        ${cyan}apiNode Management Menu${plain}                 │
│        Порт: ${green}${port}${plain} | Статус: ${status_label}          │
┠────────────────────────────────────────────────┨
│  ${green}1.${plain} Проверить статус и настройки               │
│  ${green}2.${plain} Запустить apiNode                          │
│  ${green}3.${plain} Остановить apiNode                         │
│  ${green}4.${plain} Перезапустить apiNode                      │
│  ${green}5.${plain} Просмотр логов в реальном времени          │
┠────────────────────────────────────────────────┨
│  ${green}6.${plain} Изменить порт API (откроется в файрволе)   │
│  ${green}7.${plain} Управление API-ключом (показать/сменить)   │
│  ${green}8.${plain} Синхронизировать клиентов в память Xray   │
│  ${green}9.${plain} Показать все IP-адреса сервера             │
┠────────────────────────────────────────────────┨
│  ${green}10.${plain} Обновить apiNode из GitHub                │
│  ${green}11.${plain} Удалить apiNode                            │
┠────────────────────────────────────────────────┨
│  ${green}0.${plain} Выход                                      │
╚────────────────────────────────────────────────╝
"
    read -r -p "Выберите пункт меню [0-11]: " num
    case "$num" in
        0) exit 0 ;;
        1) check_status; before_show_menu ;;
        2) docker compose up -d; echo -e "${green}Запущен.${plain}"; before_show_menu ;;
        3) docker compose down; echo -e "${red}Остановлен.${plain}"; before_show_menu ;;
        4) docker compose restart; echo -e "${green}Перезапущен.${plain}"; before_show_menu ;;
        5) docker compose logs -f --tail=100 ;;
        6) change_port; before_show_menu ;;
        7) manage_key; before_show_menu ;;
        8) sync_clients; before_show_menu ;;
        9) show_ips; before_show_menu ;;
        10) update_project; before_show_menu ;;
        11)
            read -r -p "Вы уверены, что хотите удалить контейнеры apiNode? [y/N]: " confirm_del
            if [[ "$confirm_del" =~ ^[yY]$ ]]; then
                docker compose down -v
                echo -e "${yellow}Контейнеры apiNode остановлены и удалены.${plain}"
            fi
            before_show_menu
            ;;
        *)
            echo -e "${red}Неверный пункт меню.${plain}"
            sleep 1
            show_menu
            ;;
    esac
}

before_show_menu() {
    echo -e "\n${yellow}Нажмите Enter, чтобы вернуться в меню...${plain}"
    read -r
    show_menu
}

# Обработка аргументов командной строки (CLI)
case "$1" in
    status)  check_status ;;
    start)   docker compose up -d ;;
    stop)    docker compose down ;;
    restart) docker compose restart ;;
    log|logs) docker compose logs -f --tail=100 ;;
    port)
        if [ -n "$2" ]; then
            sed -i "s/listen [0-9]\+ ssl;/listen $2 ssl;/" "$NGINX_CONF"
            open_port "$2"
            docker compose restart nginx > /dev/null 2>&1
            echo -e "${green}Порт изменен на $2${plain}"
        else
            change_port
        fi
        ;;
    key)
        if [ "$2" == "show" ]; then
            echo -e "API Key: $(get_current_key)"
        elif [ "$2" == "gen" ]; then
            new_key=$(openssl rand -hex 16)
            echo "$new_key" > "$KEY_FILE"
            echo -e "${green}Новый ключ: $new_key${plain}"
        else
            manage_key
        fi
        ;;
    sync)    sync_clients ;;
    ip|ips)  show_ips ;;
    update)  update_project ;;
    *)       show_menu ;;
esac
