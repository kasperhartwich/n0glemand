<?php

it('gives every test its own storage directory outside the repository', function () {
    expect(storage_path())
        ->not->toBe(base_path('storage'))
        ->toBe($this->testStoragePath)
        ->and(config('logging.channels.single.path'))->toBe($this->testStoragePath.'/logs/laravel.log')
        ->and(is_dir(storage_path('logs')))->toBeTrue();
});
