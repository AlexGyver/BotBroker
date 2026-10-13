# Telegram Bot API PHP Broker

Минимальный stateless passthrough-брокер для Телеграм бота, например на ESP8266/ESP32 или любым другим способом. Вместо `api.telegram.org` бот обращается к вашему серверу с обычным запросом BOT API:

```text
https://example.com/bot<TOKEN>/getUpdates...
```

Брокер редиректит запрос на хост API телеграм и возвращает ответ. Собственно это работает, если вашему провайдеру не заблокирован доступ к `api.telegram.org`, либо если сервер физически находится за пределами блокировок.

## Возможности

- Long polling (брокер парсит timeout из строки запроса)
- `multipart/form-data` и загрузка файлов
- Потоковое скачивание файлов без накопления файла в памяти PHP
- Range и условные запросы при скачивании файлов
- HTTP status/body Telegram возвращаются клиенту
- Возврат ошибок брокера в формате BOT API

## Требования

- Свой сервер с доменом
- PHP 8.x
- PHP cURL
- доступ сервера к `api.telegram.org:443`
- web/PHP timeout больше времени long polling
- лимиты `post_max_size` и `upload_max_filesize` больше отправляемых файлов
- Apache + `mod_rewrite` для приложенного `.htaccess`

По сути работает почти на любом шаред-хостинге с бесплатным доменом. Тестировалось на [beget](https://beget.com/ru) - работает отлично. На spaceweb не заработало - нет доступа к серверу тг.

Проверить текущую работоспособность beget можно на моём брокере по адресу `tg.gyver.ru`.

## Установка

Положить в корень сайта файлы из репозитория:

```text
public_html/
├── .htaccess
├── broker.php
└── test.php
```

После установки открыть:

```text
https://example.com/test.php
```

Если всё ок - должны отсутствовать красные надписи FAIL. Можно указать токен бота и нажать getMe — это прямая проверка доступа PHP-сервера к Telegram. Там же можно проверить getUpdates с long polling. Для чистого теста long polling у бота не должно быть ожидающих updates. Если update уже есть, Telegram вернёт его сразу — это нормально.

После проверки рекомендуется удалить `test.php` с публичного сервера или ограничить к нему доступ.

## Использование
На стороне бота отправлять запросы не на `api.telegram.org`, а на ваш домен. Например в FastBot2 Bot API endpoint задаётся перед подключением библиотеки:

```cpp
#define TELEGRAM_HOST "mybot.ru"
#define TELEGRAM_PORT 443
#include <FastBot2.h>
```

Токен остаётся в стандартном Telegram URL. FastBot2 отправит брокеру запрос вида:

```text
POST https://mybot.ru/bot<TOKEN>/sendMessage HTTP/1.1
Host: mybot.ru
```

Скачивание файлов использует совместимый маршрут:

```text
GET https://mybot.ru/file/bot<TOKEN>/<file_path>
```

Для других клиентов по-прежнему можно просто заменить базовый адрес
`https://api.telegram.org` на адрес брокера.

### Токен в хэдере
Для клиентов, которым это удобно, тот же Telegram-токен можно передать в хэдере `X-Bot-Token`, чтобы убрать его из URL (тогда он не попадёт в обычные access-логи сервера). Полный запрос в таком режиме может выглядеть так:

```text
POST /sendMessage HTTP/1.1
Host: mybot.ru
X-Bot-Token: 123456789:ABC...
Content-Type: application/json

{
  "chat_id": 123456789,
  "text": "Hello"
}
```

### Дополнительно
При ошибках брокер возвращает ответ в формате Telegram BOT API, прокидывает текст ошибки в `description` и добавляет поле `broker_error=true`. Пример ответа с ошибкой:

```json
{
  "ok": false,
  "broker_error": true,
  "curl_errno": 6,
  "description": "Could not resolve host: api.telegram.org"
}
```
