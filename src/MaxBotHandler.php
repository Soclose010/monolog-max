<?php

declare(strict_types=1);

namespace Soclose010\MonologMax;

use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\Curl;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;
use RuntimeException;

class MaxBotHandler extends AbstractProcessingHandler
{
    /**
     * Базовый URL MAX Bot API по умолчанию.
     * Документация указывает на platform-api2, но его сертификат выписан НУЦ Минцифры,
     * которого нет в стандартных хранилищах доверия, поэтому умолчание оставлено прежним.
     * Переключение — параметром $baseUrl конструктора, см. README.
     */
    private const DEFAULT_BOT_API = 'https://platform-api.max.ru';

    /**
     * Доступные варианты поля format согласно MAX api docs
     */
    private const AVAILABLE_FORMATS = [
        'markdown',
        'html',
    ];

    /**
     * Максимальное количество символов в поле text согласно MAX api docs
     */
    private const MAX_MESSAGE_LENGTH = 4000;

    /**
     * Токен доступа, который можно получить на https://business.max.ru/self в разделе Чат-боты → Интеграция → Получить токен
     */
    private string $accessToken;

    /**
     * Вариант форматирования, который будет использоваться в сообщении
     * Доступные форматы можно посмотреть на https://dev.max.ru/docs-api#%D0%A4%D0%BE%D1%80%D0%BC%D0%B0%D1%82%D0%B8%D1%80%D0%BE%D0%B2%D0%B0%D0%BD%D0%B8%D0%B5%20%D1%82%D0%B5%D0%BA%D1%81%D1%82%D0%B0
     * Или в AVAILABLE_FORMATS
     */
    private ?string $format;

    /**
     * Идентификатор пользователя, кому отправится сообщение
     */
    private ?int $userId;

    /**
     * Идентификатор чата, в который отправится сообщение
     */
    private ?int $chatId;

    /**
     * Отключить превью для ссылок в сообщении
     */
    private ?bool $disableLinkPreview;

    /**
     * True - разделить сообщение длиной более MAX_MESSAGE_LENGTH на части и отправить их несколькими сообщениями.
     * False - обрезать сообщение, если оно слишком длинное.
     */
    private bool $splitLongMessages;

    /**
     * Таймаут запроса к MAX API в секундах.
     */
    private int $timeout;

    /**
     * Базовый URL MAX Bot API без завершающего слэша.
     */
    private string $baseUrl;

    /**
     * @param string           $accessToken        Токен доступа MAX bot API
     * @param int|null         $userId             Идентификатор пользователя, кому отправится сообщение
     * @param int|null         $chatId             Идентификатор чата, в который отправится сообщение
     * @param string|null      $format             Формат сообщения: markdown или html
     * @param bool|null        $disableLinkPreview Отключить превью для ссылок в сообщении
     * @param bool             $splitLongMessages  Разделять длинные сообщения или обрезать их
     * @param int              $timeout            Таймаут запроса к MAX API в секундах
     * @param string|null      $baseUrl            Базовый URL MAX Bot API; null — DEFAULT_BOT_API
     */
    public function __construct(
        string $accessToken,
        ?int $userId = null,
        ?int $chatId = null,
        Level|int|string $level = Level::Debug,
        bool $bubble = true,
        ?string $format = null,
        ?bool $disableLinkPreview = true,
        bool $splitLongMessages = true,
        int $timeout = 10,
        ?string $baseUrl = null,
    ) {
        if ($accessToken === '') {
            throw new InvalidArgumentException('Токен доступа MAX не должен быть пустым.');
        }

        if ($timeout < 1) {
            throw new InvalidArgumentException('Таймаут запроса к MAX API должен быть больше 0.');
        }

        if ($baseUrl !== null && trim($baseUrl) === '') {
            throw new InvalidArgumentException('Базовый URL MAX API не должен быть пустым.');
        }

        if ($userId === null && $chatId === null) {
            throw new InvalidArgumentException('Необходимо указать userId или chatId.');
        }

        parent::__construct($level, $bubble);

        $this->accessToken = $accessToken;
        $this->userId = $userId;
        $this->chatId = $chatId;
        $this->timeout = $timeout;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BOT_API, '/');
        $this->setFormat($format);
        $this->disableLinkPreview($disableLinkPreview);
        $this->splitLongMessages($splitLongMessages);
    }

    public function setFormat(?string $format): self
    {
        if ($format !== null && !in_array($format, self::AVAILABLE_FORMATS, true)) {
            throw new InvalidArgumentException('Неизвестный формат, используйте один из: ' . implode(', ', self::AVAILABLE_FORMATS) . '.');
        }

        $this->format = $format;

        return $this;
    }

    public function disableLinkPreview(?bool $disableLinkPreview = null): self
    {
        $this->disableLinkPreview = $disableLinkPreview;

        return $this;
    }

    /**
     * True - разделить сообщение длиной более MAX_MESSAGE_LENGTH на части и отправить их несколькими сообщениями.
     * False - обрезать сообщение, если оно слишком длинное.
     */
    public function splitLongMessages(bool $splitLongMessages = true): self
    {
        $this->splitLongMessages = $splitLongMessages;

        return $this;
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter(
            dateFormat: 'd.m.Y H:i:s',
            ignoreEmptyContextAndExtra: true,
        );
    }

    protected function write(LogRecord $record): void
    {
        $this->send($record->formatted);
    }

    public function handleBatch(array $records): void
    {
        $messages = [];

        foreach ($records as $record) {
            if (!$this->isHandling($record)) {
                continue;
            }

            if (count($this->processors) > 0) {
                $record = $this->processRecord($record);
            }

            $messages[] = $record;
        }

        if ($messages !== []) {
            $this->send((string) $this->getFormatter()->formatBatch($messages));
        }
    }

    protected function send(string $message): void
    {
        foreach ($this->handleMessageLength($message) as $messagePart) {
            $this->sendCurl($messagePart);
        }
    }

    protected function sendCurl(string $message): void
    {
        $query = [
            'disable_link_preview' => $this->disableLinkPreview,
        ];

        if ($this->userId !== null) {
            $query['user_id'] = $this->userId;
        }

        if ($this->chatId !== null) {
            $query['chat_id'] = $this->chatId;
        }

        $body = [
            'text' => $message,
            'format' => $this->format,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/messages?' . http_build_query($query));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, Utils::jsonEncode($body));

        $result = Curl\Util::execute($ch);
        if (!is_string($result)) {
            throw new RuntimeException('Запрос к MAX API не удался: ответ не получен');
        }

        $response = json_decode($result, true);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($httpCode < 200 || $httpCode >= 300) {
            $code = is_array($response) ? ($response['code'] ?? null) : null;
            $message = is_array($response) ? ($response['message'] ?? null) : null;

            throw new RuntimeException(sprintf('Ошибка MAX API. HTTP-код: %s, код: %s, сообщение: %s', $httpCode, $code, $message));
        }

        if (!is_array($response)) {
            throw new RuntimeException('Ошибка MAX API. Некорректный JSON-ответ');
        }
    }

    /**
     * Обработать слишком длинное сообщение: обрезать или разбить на несколько частей.
     *
     * @return list<string>
     */
    private function handleMessageLength(string $message): array
    {
        $truncatedMarker = ' (...обрезано)';

        if (!$this->splitLongMessages && mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
            return [mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - mb_strlen($truncatedMarker, 'UTF-8'), 'UTF-8') . $truncatedMarker];
        }

        $messages = [];

        do {
            $messages[] = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8');
            $message = mb_substr($message, self::MAX_MESSAGE_LENGTH, null, 'UTF-8');
        } while ($message !== '');

        return $messages;
    }
}
