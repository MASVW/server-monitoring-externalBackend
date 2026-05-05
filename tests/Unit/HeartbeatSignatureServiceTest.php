<?php

namespace Tests\Unit;

use App\Services\HeartbeatSignatureService;
use Tests\TestCase;

class HeartbeatSignatureServiceTest extends TestCase
{
    public function test_signature_verification_accepts_plain_and_prefixed_signatures(): void
    {
        $service = new HeartbeatSignatureService();

        $rawBody = '{"node_id":"node-01"}';
        $timestamp = now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');
        $secret = 'test-secret';

        $expected = $service->computeSignature($rawBody, $timestamp, $secret);

        $plain = $service->verifyRequestSignature($rawBody, $timestamp, $expected, $secret);
        $prefixed = $service->verifyRequestSignature($rawBody, $timestamp, 'sha256='.$expected, $secret);

        $this->assertTrue($plain['ok']);
        $this->assertTrue($prefixed['ok']);
    }

    public function test_signature_verification_rejects_invalid_signature(): void
    {
        $service = new HeartbeatSignatureService();

        $result = $service->verifyRequestSignature('body', now('UTC')->toIso8601String(), 'bad', 'secret');

        $this->assertFalse($result['ok']);
    }

    public function test_body_signature_verification_accepts_plain_and_prefixed_signatures(): void
    {
        $service = new HeartbeatSignatureService();
        $rawBody = '{"type":"internal-server-heartbeat"}';
        $secret = 'test-secret';

        $expected = $service->computeBodySignature($rawBody, $secret);

        $plain = $service->verifyBodySignature($rawBody, $expected, $secret);
        $prefixed = $service->verifyBodySignature($rawBody, 'sha256='.$expected, $secret);

        $this->assertTrue($plain['ok']);
        $this->assertTrue($prefixed['ok']);
    }

    public function test_timestamp_drift_validation_rejects_far_old_timestamp(): void
    {
        config()->set('heartbeat.allowed_drift_seconds', 300);

        $service = new HeartbeatSignatureService();
        $result = $service->validateTimestampDrift(now('UTC')->subMinutes(10)->toIso8601String());

        $this->assertFalse($result['ok']);
    }
}
