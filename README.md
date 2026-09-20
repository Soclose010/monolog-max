# Monolog MAX Handler

Позволяет отправлять логи Monolog в MAX пользователю или в чат.

## Требования

- PHP `>=8.1`
- `ext-curl`
- `ext-mbstring`
- токен доступа MAX-бота из [business.max.ru/self](https://business.max.ru/self), раздел **Чат-боты** -> **Интеграция** -> **Получить токен**
- `user_id` или `chat_id` получателя

## Установка

```shell
composer require soclose010/monolog-max
```

## Использование

```php
<?php

use Monolog\Level;
use Monolog\Logger;
use Soclose010\MonologMax\MaxBotHandler;

require 'vendor/autoload.php';

$logger = new Logger('My project');
$handler = new MaxBotHandler(
    accessToken: 'access_token',
    userId: 987654321,          // ID пользователя, можно null если задан chatId
    chatId: null,               // ID чата, можно null если задан userId
    level: Level::Error,        // по умолчанию Level::Debug
    bubble: true,               // по умолчанию true
    format: 'html',             // null, 'html' или 'markdown'
    disableLinkPreview: true,  // false отключает превью ссылок согласно документации MAX API
    splitLongMessages: true,    // true: разбивать сообщения длиннее 4000 символов, false: обрезать
    timeout: 10,                // таймаут запроса к MAX API в секундах
    baseUrl: null,              // null — https://platform-api.max.ru
);

$logger->pushHandler($handler);

$logger->error('Error!');
```

Если переданы и `userId`, и `chatId`, handler отправит оба query-параметра в MAX API без локального приоритета. Итоговое поведение определяется MAX API.

## Параметры
Параметры можно менять после создания handler:

```php
$handler
    ->setFormat('markdown')
    ->disableLinkPreview(false)
    ->splitLongMessages(false);
```

Сообщения длиннее 4000 символов по умолчанию разбиваются на несколько сообщений. Если вызвать `splitLongMessages(false)`, сообщение будет обрезано с маркером `(...обрезано)`.

Автоматическое разбиение длинных сообщений может разделить тег, ссылку или markdown-разметку между двумя сообщениями. Для форматированных сообщений безопаснее заранее формировать короткий текст или использовать `splitLongMessages(false)`.

Параметр `disableLinkPreview` передается в MAX API как `disable_link_preview`. Согласно документации MAX API, значение `false` отключает превью ссылок.

## Адрес API и сертификаты

Параметр `baseUrl` задаёт адрес MAX Bot API. По умолчанию — `https://platform-api.max.ru`.

Документация MAX указывает на другой домен, `platform-api2.max.ru`, но переключаться на него стоит осознанно: домены отличаются не только адресом, но и тем, кто выписал им сертификат.

| домен | издатель сертификата |
| --- | --- |
| `platform-api.max.ru` | Let's Encrypt |
| `platform-api2.max.ru` | НУЦ Минцифры (`Russian Trusted Root CA`) |

Корня НУЦ Минцифры нет ни в `ca-certificates`, ни в бандлах браузеров, поэтому запрос к `platform-api2` падает с `cURL error 60: SSL certificate problem` ещё до авторизации, пока корень не добавлен в доверенные на сервере:

```shell
curl -sS -o root.cer https://gu-st.ru/content/Other/doc/russian_trusted_root_ca.cer
sudo install -m 644 root.cer /usr/local/share/ca-certificates/russian_trusted_root_ca.crt
sudo update-ca-certificates
```

После этого домен задаётся параметром:

```php
$handler = new MaxBotHandler(
    accessToken: 'access_token',
    chatId: 987654321,
    baseUrl: 'https://platform-api2.max.ru',
);
```

Умолчание оставлено прежним намеренно: смена домена по умолчанию сломала бы отправку у всех, кто не добавлял корень в доверенные. Если адрес снова сменится, его можно задать здесь, не дожидаясь обновления пакета.

## Устойчивость: сбои отправки и повторы

`MaxBotHandler` выбрасывает `RuntimeException`, если отправка сообщения не удалась. При необходимости обработку ошибок и повторяющихся сообщений можно настроить стандартными `handler'ами` Monolog.

## Как получить ID получателя

`chat_id` можно взять из URL веб-версии MAX при открытии нужного чата.

`user_id` можно получить через API. Например, отправьте сообщение пользователю в личные сообщения и возьмите идентификатор из блока `recipient` в ответе API.

## Тесты

Тесты handler ходят в реальный MAX API. Для запуска добавьте `.env` в корне пакета:

```dotenv
MAX_TOKEN=access_token
MAX_USER_ID=123456789
MAX_CHAT_ID=987654321
```

`MAX_USER_ID` и `MAX_CHAT_ID` могут быть пустыми, но должен быть указан хотя бы один получатель.

Запуск:

```shell
composer test
```

## Проверка стиля

```shell
composer check-code
composer fix-code
```
