<?php

namespace Tests\Unit;

use App\Logging\SafeContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class SafeContextProcessorTest extends TestCase
{
    public function test_secrets_and_raw_exceptions_never_reach_logs(): void
    {
        $record = new LogRecord(new \DateTimeImmutable, 'workflow', Level::Error, 'SQL error with secret-token', [
            'request_id' => 'request-1', 'order_id' => 42,
            'password' => 'secret-password', 'authorization' => 'Bearer secret-token',
            'body' => ['card_number' => '4111111111111111', 'cvv' => '123'],
            'exception' => new \RuntimeException('Sensitive SQL and secret-token'),
        ], ['customer_email' => 'private@example.test']);
        $safe = (new SafeContextProcessor)($record);
        $json = json_encode($safe->toArray());
        foreach (['secret', '4111111111111111', 'private@example.test', '123'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame(42, $safe->context['order_id']);
        $this->assertSame(\RuntimeException::class, $safe->context['exception_class']);
    }
}
