<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * The per-test storage directory, so no test reads or deletes the real
     * storage/ and parallel workers never share files.
     */
    protected string $testStoragePath;

    /**
     * @return Application
     */
    public function createApplication()
    {
        $this->testStoragePath = sys_get_temp_dir().'/n0glemand-tests/'.Str::uuid();

        foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
            mkdir($this->testStoragePath.'/'.$dir, 0755, true);
        }

        $_ENV['LARAVEL_STORAGE_PATH'] = $this->testStoragePath;

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_ENV['LARAVEL_STORAGE_PATH']);

        (new Filesystem)->deleteDirectory($this->testStoragePath);
    }
}
