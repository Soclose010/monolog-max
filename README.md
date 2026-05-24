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

## Как получить ID получателя

`chat_id` можно взять из URL веб-версии MAX при открытии нужного чата.

`user_id` можно получить через API. Например, отправьте сообщение пользователю в личные сообщения и возьмите идентификатор из блока `recipient` в ответе API.

## Тесты

Тесты handler ходят в реальный MAX API. Для запуска добавьте `code/backend/.env`:

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
