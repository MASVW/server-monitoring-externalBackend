<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class HeartbeatSignatureService
{
    public function normalizeSignature(?string $signatureHeader): string
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            return '';
        }

        return str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;
    }

    public function computeSignature(string $rawBody, string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $rawBody.$timestamp, $secret);
    }

    public function verifyRequestSignature(string $rawBody, string $timestamp, ?string $signature, string $secret): array
    {
        if ($secret === '') {
            return [
                'ok' => false,
                'reason' => 'HMAC secret is not configured',
            ];
        }

        $normalizedSignature = $this->normalizeSignature($signature);
        $expected = $this->computeSignature($rawBody, $timestamp, $secret);

        return [
            'ok' => hash_equals($expected, $normalizedSignature),
            'expected' => $expected,
        ];
    }

    public function validateTimestampDrift(string $timestamp): array
    {
        try {
            $date = CarbonImmutable::parse($timestamp)->utc();
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'reason' => 'Invalid timestamp format',
            ];
        }

        $now = CarbonImmutable::now('UTC');
        $diffSeconds = (int) abs($now->diffInSeconds($date, false));

        if ($diffSeconds > config('heartbeat.allowed_drift_seconds')) {
            return [
                'ok' => false,
                'reason' => 'Timestamp outside allowed drift window',
            ];
        }

        return [
            'ok' => true,
            'date' => $date,
        ];
    }
}
