<?php

if (!defined('ABSPATH')) {
    exit;
}

class SWB_Rest_Controller
{
    private const NAMESPACE = 'slack-welcome-bot/v1';
    private const ROUTE = '/events';

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_event'],
            // Slack authenticates via the signature (see verify_signature), not via WP users/nonces.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_event(WP_REST_Request $request)
    {
        $raw_body = $request->get_body();
        $timestamp = $request->get_header('x_slack_request_timestamp');
        $signature = $request->get_header('x_slack_signature');

        $options = SWB_Settings::get_options();

        if (!self::verify_signature($options['signing_secret'], $raw_body, $timestamp, $signature)) {
            return new WP_REST_Response('Invalid signature', 401);
        }

        $payload = json_decode($raw_body, true);

        if (!is_array($payload)) {
            return new WP_REST_Response('Invalid payload', 400);
        }

        // URL verification handshake (once, when setting up the event subscription)
        if (($payload['type'] ?? null) === 'url_verification') {
            return new WP_REST_Response($payload['challenge'] ?? '', 200);
        }

        $event = $payload['event'] ?? null;

        if (!is_array($event) || ($event['type'] ?? null) !== 'team_join') {
            return new WP_REST_Response('ignored', 200);
        }

        // Deduplication by event_id (Slack can, in rare cases, deliver an event more than
        // once - including its own retries on timeouts and the like). The key is only set
        // AFTER successful delivery (see below), so that a Slack retry following a failed
        // delivery attempt can actually retry the DM, instead of being wrongly discarded
        // as a "duplicate".
        $event_id = $payload['event_id'] ?? null;
        $transient_key = $event_id !== null ? 'swb_evt_' . md5((string) $event_id) : null;

        if ($transient_key !== null && get_transient($transient_key)) {
            return new WP_REST_Response('duplicate', 200);
        }

        $user_id = $event['user'] ?? null;

        // Slack delivers the full user object on team_join, not just an ID.
        if (is_array($user_id)) {
            $user_id = $user_id['id'] ?? null;
        }

        if (!is_string($user_id)) {
            return new WP_REST_Response('ok', 200);
        }

        if (!swb_send_welcome_dm($user_id)) {
            // Don't mark as done + non-2xx: triggers Slack's own retry, instead of
            // silently confirming a failed delivery as "ok".
            return new WP_REST_Response('delivery failed', 500);
        }

        if ($transient_key !== null) {
            set_transient($transient_key, 1, DAY_IN_SECONDS);
        }

        return new WP_REST_Response('ok', 200);
    }

    public static function verify_signature(string $signing_secret, string $raw_body, ?string $timestamp, ?string $signature): bool
    {
        if ($signing_secret === '' || $timestamp === null || $signature === null) {
            return false;
        }

        // Protection against replay attacks: reject requests older than 5 minutes.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $base_string = "v0:{$timestamp}:{$raw_body}";
        $computed = 'v0=' . hash_hmac('sha256', $base_string, $signing_secret);

        return hash_equals($computed, $signature);
    }
}

/**
 * Builds the message blocks: greeting + content of the selected WordPress page.
 */
function swb_build_blocks(string $user_id, int $page_id): array
{
    $greeting = sprintf(
        /* translators: %s: Slack user mention, e.g. <@U12345> */
        __('Herzlich willkommen im Team, %s! :wave:', 'slack-welcome-bot'),
        "<@{$user_id}>"
    );

    $blocks = [
        [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $greeting],
        ],
    ];

    $page = $page_id > 0 ? get_post($page_id) : null;

    // "private" pages are allowed: the welcome DM reads the content via get_post()
    // directly from the database, regardless of WordPress visibility or a page
    // password (see the notice on the settings page).
    if ($page instanceof WP_Post && in_array($page->post_status, ['publish', 'private'], true)) {
        // Many plugins (SEO, related posts, shortcodes) hook into "the_content" and
        // expect a valid global $post there. Since we're running outside the normal
        // loop (webhook request), we need to set this up explicitly and then restore
        // it cleanly afterwards, so we don't affect other hooks/requests.
        global $post;
        $previous_post = $post;

        $post = $page;
        setup_postdata($post);

        $rendered_html = apply_filters('the_content', $page->post_content);

        if ($previous_post instanceof WP_Post) {
            $post = $previous_post;
            setup_postdata($post);
        } else {
            $post = null;
            wp_reset_postdata();
        }

        $slack_text = SWB_Html_Converter::to_slack_mrkdwn($rendered_html);

        if ($slack_text !== '') {
            foreach (SWB_Html_Converter::split_into_blocks($slack_text) as $chunk) {
                $blocks[] = [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $chunk],
                ];
            }
        }
    } else {
        $blocks[] = [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => __("Es wurde noch keine Regel-Seite in den Plugin-Einstellungen ausgewählt.\nBitte unter *Einstellungen → Slack Welcome Bot* nachtragen.", 'slack-welcome-bot'),
            ],
        ];
    }

    // Slack allows a max. of 50 blocks per message.
    return array_slice($blocks, 0, 50);
}

/**
 * Opens the DM and sends the welcome message.
 *
 * @return bool True if the message was verifiably delivered.
 */
function swb_send_welcome_dm(string $user_id): bool
{
    $options = SWB_Settings::get_options();

    if ($options['bot_token'] === '') {
        error_log('Slack Welcome Bot: kein Bot-Token konfiguriert.');
        return false;
    }

    $client = new SWB_Slack_Client($options['bot_token']);
    $channel_id = $client->open_dm($user_id);

    if ($channel_id === null) {
        return false;
    }

    $blocks = swb_build_blocks($user_id, (int) $options['rules_page_id']);

    return $client->post_message($channel_id, __('Willkommen im Team! Hier sind die wichtigsten Regeln.', 'slack-welcome-bot'), $blocks);
}
