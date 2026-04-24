<?php

namespace App\Services\Instagram;

final readonly class InstagramLoginResult
{
    public function __construct(
        public InstagramLoginStatus $status,
        public ?string $cookiesTxt = null,
        public ?string $errorMessage = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === InstagramLoginStatus::Ok;
    }
}
