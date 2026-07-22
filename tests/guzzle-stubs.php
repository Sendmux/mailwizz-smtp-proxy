<?php

declare(strict_types=1);

namespace GuzzleHttp {
    final class Client
    {
        /** @var array<int, array{url: string, options: array}> */
        public static array $requests = [];

        public function post(string $url, array $options): TestResponse
        {
            self::$requests[] = compact('url', 'options');

            return new TestResponse('{"ok":true,"data":{"status":"queued","message_id":"eml_test"}}');
        }
    }

    final class TestResponse
    {
        private string $body;

        public function __construct(string $body)
        {
            $this->body = $body;
        }

        public function getBody(): string
        {
            return $this->body;
        }
    }
}
namespace GuzzleHttp\Exception {
    class RequestException extends \Exception
    {
        public function hasResponse(): bool
        {
            return false;
        }

        public function getResponse()
        {
            return null;
        }
    }
}
