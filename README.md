# Telegram Bot API PHP Broker

Минимальный stateless passthrough-брокер для Телеграм бота, например на ESP8266/ESP32 или любым другим способом. Вместо `api.telegram.org` бот обращается к вашему серверу с обычным запросом BOT API:

```text
https://example.com/bot<TOKEN>/getUpdates...
```

Брокер редиректит запрос на хост API телеграм и возвращает ответ. Собственно это работает, если вашему провайдеру не заблокирован доступ к `api.telegram.org`, либо если сервер физически находится за пределами блокировок.

## Возможности

- Long polling (брокер парсит timeout из строки запроса)
- `multipart/form-data` и загрузка файлов
- HTTP status/body Telegram возвращаются клиенту

## Требования

- Свой сервер с доменом
- PHP 8.x
- PHP cURL
- доступ сервера к `api.telegram.org:443`
- web/PHP timeout больше времени long polling
- Apache + `mod_rewrite` для приложенного `.htaccess`

По сути работает почти на любом шаред-хостинге с бесплатным доменом. Тестировалось на [beget](https://beget.com/ru) - работает отлично. На spaceweb не заработало - нет доступа к серверу тг.

Проверить текущую работоспособность beget можно на моём брокере по адресу `tg.gyver.ru`.

## Установка

Положить в корень сайта файлы из репозитория:

```text
public_html/
├── .htaccess
├── broker.php
└── test.html
```

После установки открыть:

```text
https://example.com/test.html
```

Ввести token и chat ID. Страница умеет проверять:

1. соединение с Telegram через broker (`getMe`)
2. long polling с замером времени
3. отправку сообщения
4. multipart-загрузку файла

Для чистого теста long polling у бота не должно быть ожидающих updates. Если update уже есть, Telegram вернёт его сразу — это нормально.

После проверки рекомендуется удалить `test.html` с публичного сервера или ограничить к нему доступ.

На стороне бота отправлять запросы не на `api.telegram.org`, а на ваш домен. В библиотеке FastBot2 например это можно сделать так:

```cpp
// перед подключением библиотеки
#define TELEGRAM_HOST "tg.gyver.ru"
```

## Дополнительно
При ошибках брокер возвращает ответ в формате Telegram BOT API, прокидывает текст ошибки в `description` и добавляет поле `broker_error=true`. Пример ответа с ошибкой:

```json
{
  "ok": false,
  "broker_error": true,
  "curl_errno": 6,
  "description": "Could not resolve host: api.telegram.org"
}
```