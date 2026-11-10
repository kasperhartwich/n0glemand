<?php

namespace App\Services\Instagram;

final readonly class InstagramCheckResult
{
    public function __construct(
        public InstagramCheckStatus $status,
        public string $message,
    ) {}

    public function isOk(): bool
    {
        return $this->status === InstagramCheckStatus::Ok;
    }
}
