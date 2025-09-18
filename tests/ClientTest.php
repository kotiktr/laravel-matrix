<?php

namespace Kotiktr\LaravelMatrix\Tests;

use Kotiktr\LaravelMatrix\Client;

class ClientTest extends TestCase
{
    public function test_login_returns_null_when_config_missing()
    {
        $client = new Client([]);
        $this->assertNull($client->login());
    }
}
