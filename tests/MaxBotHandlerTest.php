<?php

declare(strict_types=1);

namespace Soclose010\MonologMax\Tests;

use Dotenv\Dotenv;
use Monolog\Handler\BufferHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Soclose010\MonologMax\MaxBotHandler;

class MaxBotHandlerTest extends TestCase
{
    private ?string $accessToken;
    private ?int $userId;
    private ?int $chatId;

    protected function setUp(): void
    {
        if (file_exists(dirname(__DIR__) . '/.env')) {
            Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
        }

        $accessToken = $_ENV['MAX_TOKEN'] ?? getenv('MAX_TOKEN');
        $userId = $_ENV['MAX_USER_ID'] ?? getenv('MAX_USER_ID');
        $chatId = $_ENV['MAX_CHAT_ID'] ?? getenv('MAX_CHAT_ID');

        $this->accessToken = $accessToken === false || $accessToken === '' ? null : (string) $accessToken;
        $this->userId = $userId === false || $userId === '' ? null : (int) $userId;
        $this->chatId = $chatId === false || $chatId === '' ? null : (int) $chatId;
    }

    public function testWithValidArguments(): void
    {
        if ($this->accessToken === null || ($this->userId === null && $this->chatId === null)) {
            $this->markTestSkipped('Токен доступа MAX или получатель не указаны');
        }

        $logger = new Logger('PHPUnit');
        $handler = new MaxBotHandler(
            $this->accessToken,
            $this->userId,
            $this->chatId,
            Level::Debug,
            true,
            'html',
            true,
            true,
        );
        $logger->pushHandler($handler);

        try {
            $logger->debug('PHPUnit - MAX valid arguments test: <b>html</b> https://dev.max.ru');
            $logger->debug('PHPUnit - MAX long message split test ' . str_repeat('тест ', 1000));
        } catch (\Exception $exception) {
            $this->fail('Было выброшено исключение: ' . $exception->getMessage());
        }

        sleep(1);

        $this->assertTrue(true);

        $logger = new Logger('PHPUnit');
        $handler = new MaxBotHandler($this->accessToken, $this->userId, $this->chatId, Level::Debug, true, 'html');
        $handler = new BufferHandler($handler);
        $logger->pushHandler($handler);

        try {
            $logger->debug('PHPUnit - MAX batch processing test 1');
            $logger->debug('PHPUnit - MAX batch processing test 2');
            $logger->debug('PHPUnit - MAX batch processing test 3');
            $handler->close();
        } catch (\Exception $exception) {
            $this->fail('Было выброшено исключение: ' . $exception->getMessage());
        }

        $this->assertTrue(true);
    }

    public function testWithInvalidToken(): void
    {
        sleep(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verify.token');

        $logger = new Logger('PHPUnit');
        $handler = new MaxBotHandler('invalid-token', $this->userId ?? 1, $this->chatId, Level::Debug);
        $logger->pushHandler($handler);

        $logger->debug('PHPUnit - MAX invalid token test');
    }

    public function testWithInvalidRecipient(): void
    {
        sleep(1);

        if ($this->accessToken === null) {
            $this->markTestSkipped('Токен доступа MAX не указан');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dialog.not.found');

        $logger = new Logger('PHPUnit');
        $handler = new MaxBotHandler($this->accessToken, 1, null, Level::Debug);
        $logger->pushHandler($handler);

        $logger->debug('PHPUnit - MAX invalid recipient test');
    }

    public function testRecipientIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Необходимо указать userId или chatId.');

        new MaxBotHandler('token');
    }

    public function testAccessTokenMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Токен доступа MAX не должен быть пустым.');

        new MaxBotHandler('', 1);
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Таймаут запроса к MAX API должен быть больше 0.');

        new MaxBotHandler('token', 1, timeout: 0);
    }

    public function testBaseUrlMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Базовый URL MAX API не должен быть пустым.');

        new MaxBotHandler('token', 1, baseUrl: '   ');
    }

    public function testFormatValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Неизвестный формат');

        new MaxBotHandler('token', 1, null, format: 'plain');
    }
}
