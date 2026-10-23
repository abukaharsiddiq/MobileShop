<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // RefreshDatabase (setUpTraits er bhitore) puro database muche fele -
    // tai tar AGE dekhe nite hobe jate bhul kore asol database e na chole
    protected function setUpTraits()
    {
        $database = config('database.connections.' . config('database.default') . '.database');
        if (!str_ends_with((string) $database, '_test') && $database !== ':memory:') {
            throw new \RuntimeException("Refusing to run tests against database \"{$database}\". Use a database whose name ends with _test.");
        }

        return parent::setUpTraits();
    }
}
