<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Belt-and-suspenders on top of phpunit.xml's SESSION_DRIVER=array: force
    // it here too, so feature tests never touch a "sessions" DB table that no
    // migration creates, even if some runner/CI setup loads .env's
    // SESSION_DRIVER=database ahead of phpunit.xml's env block.
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'array']);
    }
}
