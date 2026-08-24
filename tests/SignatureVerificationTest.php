<?php

use PHPUnit\Framework\TestCase;

final class SignatureVerificationTest extends TestCase
{
    private const SECRET = 'super-secret';

    private function sign(string $timestamp, string $body): string
    {
        return 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET);
    }

    public function test_accepts_a_valid_signature(): void
    {
        $timestamp = (string) time();
        $body = '{"type":"event_callback"}';
        $signature = $this->sign($timestamp, $body);

        $this->assertTrue(SWB_Rest_Controller::verify_signature(self::SECRET, $body, $timestamp, $signature));
    }

    public function test_rejects_a_tampered_body(): void
    {
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, '{"type":"event_callback"}');

        $this->assertFalse(SWB_Rest_Controller::verify_signature(self::SECRET, '{"type":"tampered"}', $timestamp, $signature));
    }

    public function test_rejects_signature_signed_with_wrong_secret(): void
    {
        $timestamp = (string) time();
        $body = '{"type":"event_callback"}';
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'wrong-secret');

        $this->assertFalse(SWB_Rest_Controller::verify_signature(self::SECRET, $body, $timestamp, $signature));
    }

    public function test_rejects_expired_timestamp_to_prevent_replay(): void
    {
        $timestamp = (string) (time() - 301);
        $body = '{"type":"event_callback"}';
        $signature = $this->sign($timestamp, $body);

        $this->assertFalse(SWB_Rest_Controller::verify_signature(self::SECRET, $body, $timestamp, $signature));
    }

    public function test_accepts_timestamp_just_inside_the_replay_window(): void
    {
        $timestamp = (string) (time() - 299);
        $body = '{"type":"event_callback"}';
        $signature = $this->sign($timestamp, $body);

        $this->assertTrue(SWB_Rest_Controller::verify_signature(self::SECRET, $body, $timestamp, $signature));
    }

    public function test_rejects_when_signing_secret_not_configured(): void
    {
        $timestamp = (string) time();
        $body = '{"type":"event_callback"}';
        $signature = $this->sign($timestamp, $body);

        $this->assertFalse(SWB_Rest_Controller::verify_signature('', $body, $timestamp, $signature));
    }

    public function test_rejects_missing_timestamp_or_signature(): void
    {
        $body = '{"type":"event_callback"}';

        $this->assertFalse(SWB_Rest_Controller::verify_signature(self::SECRET, $body, null, 'v0=anything'));
        $this->assertFalse(SWB_Rest_Controller::verify_signature(self::SECRET, $body, (string) time(), null));
    }
}
