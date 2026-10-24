<?php

namespace App\Services\Mam;

/**
 * What MAM reported. dynamicSeedbox.php returns the IP/ASN it sees on every
 * response (also on 403 and 429); jsonIp.php returns only those.
 */
final readonly class MamResult
{
    public function __construct(
        public int $httpStatus,
        public ?bool $success,
        public ?string $message,
        public ?string $ip,
        public ?int $asn,
        public ?string $asOrg,
        public ?string $rotatedCookie = null,
    ) {}
}
