<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers SWB_Rest_Controller::handle_event(), the webhook entry point that
 * every incoming Slack event goes through: signature verification, retry
 * and duplicate-event handling, event filtering, and triggering the
 * welcome DM.
 */
final class RestControllerHandleEventTest extends TestCase
{
    private const SECRET = 'super-secret';

    protected function setUp(): void
    {
        SWB_Test_State::reset();
        SWB_Test_State::$options['swb_settings'] = ['signing_secret' => self::SECRET];
    }

    private function build_request(array $payload, array $extra_headers = []): WP_REST_Request
    {
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET);

        $request = new WP_REST_Request();
        $request->set_body($body);
        $request->set_header('x-slack-request-timestamp', $timestamp);
        $request->set_header('x-slack-signature', $signature);

        foreach ($extra_headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return $request;
    }

    private function handle(WP_REST_Request $request): WP_REST_Response
    {
        return SWB_Rest_Controller::instance()->handle_event($request);
    }

    public function test_rejects_request_with_invalid_signature(): void
    {
        $request = new WP_REST_Request();
        $request->set_body(json_encode(['type' => 'event_callback']));
        $request->set_header('x-slack-request-timestamp', (string) time());
        $request->set_header('x-slack-signature', 'v0=invalid');

        $response = $this->handle($request);

        $this->assertSame(401, $response->get_status());
        $this->assertEmpty(SWB_Test_State::$http_calls);
    }

    public function test_rejects_non_json_body(): void
    {
        $timestamp = (string) time();
        $body = 'not-json';
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET);

        $request = new WP_REST_Request();
        $request->set_body($body);
        $request->set_header('x-slack-request-timestamp', $timestamp);
        $request->set_header('x-slack-signature', $signature);

        $response = $this->handle($request);

        $this->assertSame(400, $response->get_status());
    }

    public function test_answers_url_verification_handshake(): void
    {
        $request = $this->build_request(['type' => 'url_verification', 'challenge' => 'abc123']);

        $response = $this->handle($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('abc123', $response->get_data());
    }

    public function test_ignores_non_team_join_events(): void
    {
        $request = $this->build_request(['type' => 'event_callback', 'event' => ['type' => 'message']]);

        $response = $this->handle($request);

        $this->assertSame('ignored', $response->get_data());
        $this->assertEmpty(SWB_Test_State::$http_calls);
    }

    public function test_missing_bot_token_is_reported_as_delivery_failure(): void
    {
        $request = $this->build_request(['type' => 'event_callback', 'event' => ['type' => 'team_join', 'user' => 'U1']]);

        $response = $this->handle($request);

        $this->assertSame(500, $response->get_status());
        $this->assertEmpty(SWB_Test_State::$http_calls);
    }

    public function test_sends_welcome_dm_for_a_team_join_event(): void
    {
        SWB_Test_State::$options['swb_settings']['bot_token'] = 'xoxb-test';
        $request = $this->build_request(['type' => 'event_callback', 'event' => ['type' => 'team_join', 'user' => 'U1']]);

        $response = $this->handle($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('ok', $response->get_data());

        $this->assertCount(2, SWB_Test_State::$http_calls);
        $this->assertStringContainsString('conversations.open', SWB_Test_State::$http_calls[0]['url']);
        $this->assertSame('U1', SWB_Test_State::$http_calls[0]['args']['body']['users']);

        $this->assertStringContainsString('chat.postMessage', SWB_Test_State::$http_calls[1]['url']);
        $this->assertSame('D123', SWB_Test_State::$http_calls[1]['args']['body']['channel']);
        $this->assertStringContainsString('U1', SWB_Test_State::$http_calls[1]['args']['body']['blocks']);
    }

    public function test_slack_user_object_form_is_supported(): void
    {
        SWB_Test_State::$options['swb_settings']['bot_token'] = 'xoxb-test';
        $request = $this->build_request([
            'type' => 'event_callback',
            'event' => ['type' => 'team_join', 'user' => ['id' => 'U2']],
        ]);

        $this->handle($request);

        $this->assertCount(2, SWB_Test_State::$http_calls);
        $this->assertSame('U2', SWB_Test_State::$http_calls[0]['args']['body']['users']);
    }

    public function test_deduplicates_a_retry_after_successful_delivery(): void
    {
        SWB_Test_State::$options['swb_settings']['bot_token'] = 'xoxb-test';
        $payload = [
            'type' => 'event_callback',
            'event_id' => 'Ev123',
            'event' => ['type' => 'team_join', 'user' => 'U1'],
        ];

        $first = $this->handle($this->build_request($payload));
        // Slack retries by resending the identical event, flagged via this header.
        $retry = $this->handle($this->build_request($payload, ['x-slack-retry-num' => '1']));

        $this->assertSame('ok', $first->get_data());
        $this->assertSame('duplicate', $retry->get_data());
        $this->assertCount(2, SWB_Test_State::$http_calls); // only the first request placed Slack calls
    }

    public function test_failed_delivery_returns_5xx_and_does_not_mark_the_event_processed(): void
    {
        SWB_Test_State::$options['swb_settings']['bot_token'] = 'xoxb-test';
        SWB_Test_State::$slack_should_fail = true;
        $payload = [
            'type' => 'event_callback',
            'event_id' => 'Ev123',
            'event' => ['type' => 'team_join', 'user' => 'U1'],
        ];

        $response = $this->handle($this->build_request($payload));

        $this->assertSame(500, $response->get_status());
        $this->assertFalse(SWB_Test_State::$transients['swb_evt_' . md5('Ev123')] ?? false);
    }

    public function test_slack_retry_after_a_failed_delivery_gets_a_real_second_attempt(): void
    {
        SWB_Test_State::$options['swb_settings']['bot_token'] = 'xoxb-test';
        $payload = [
            'type' => 'event_callback',
            'event_id' => 'Ev123',
            'event' => ['type' => 'team_join', 'user' => 'U1'],
        ];

        SWB_Test_State::$slack_should_fail = true;
        $first = $this->handle($this->build_request($payload));

        // The underlying issue resolves itself before Slack's retry arrives.
        SWB_Test_State::$slack_should_fail = false;
        $retry = $this->handle($this->build_request($payload, ['x-slack-retry-num' => '1']));

        $this->assertSame(500, $first->get_status());
        $this->assertSame(200, $retry->get_status());
        $this->assertSame('ok', $retry->get_data());
        // 1 failed conversations.open + a full successful attempt (open + post).
        $this->assertCount(3, SWB_Test_State::$http_calls);
    }
}
