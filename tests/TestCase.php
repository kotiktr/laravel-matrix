<?php

namespace Kotiktr\LaravelMatrix\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Kotiktr\LaravelMatrix\MatrixServiceProvider::class,
        ];
    }
}
