<?php
namespace SimpleMappr;

final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $identifier,
        public string $displayName,
    ) {}
}
