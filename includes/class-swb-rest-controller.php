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
            // Slack authentifiziert sich über die Signatur (siehe verify_signature), nicht über WP-Nutzer/Nonces.
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

        // URL-Verification-Handshake (einmalig beim Einrichten der Event Subscription)
        if (($payload['type'] ?? null) === 'url_verification') {
            return new WP_REST_Response($payload['challenge'] ?? '', 200);
        }

        // Retries von Slack (bei Timeout o.ä.) ignorieren - sonst gäbe es doppelte DMs.
        if ($request->get_header('x_slack_retry_num') !== null) {
            return new WP_REST_Response('ok', 200);
        }

        $event = $payload['event'] ?? null;

        if (!is_array($event) || ($event['type'] ?? null) !== 'team_join') {
            return new WP_REST_Response('ignored', 200);
        }

        // Deduplizierung anhand der event_id (Slack kann Events in seltenen Fällen mehrfach zustellen).
        $event_id = $payload['event_id'] ?? null;

        if ($event_id !== null) {
            $transient_key = 'swb_evt_' . md5((string) $event_id);

            if (get_transient($transient_key)) {
                return new WP_REST_Response('duplicate', 200);
            }

            set_transient($transient_key, 1, DAY_IN_SECONDS);
        }

        $user_id = $event['user'] ?? null;

        // Slack liefert bei team_join das komplette User-Objekt, keine bloße ID.
        if (is_array($user_id)) {
            $user_id = $user_id['id'] ?? null;
        }

        if (is_string($user_id)) {
            swb_send_welcome_dm($user_id);
        }

        return new WP_REST_Response('ok', 200);
    }

    public static function verify_signature(string $signing_secret, string $raw_body, ?string $timestamp, ?string $signature): bool
    {
        if ($signing_secret === '' || $timestamp === null || $signature === null) {
            return false;
        }

        // Schutz vor Replay-Attacken: Requests älter als 5 Minuten ablehnen.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $base_string = "v0:{$timestamp}:{$raw_body}";
        $computed = 'v0=' . hash_hmac('sha256', $base_string, $signing_secret);

        return hash_equals($computed, $signature);
    }
}

/**
 * Baut die Nachrichten-Blocks: Begrüßung + Inhalt der ausgewählten WordPress-Seite.
 */
function swb_build_blocks(string $user_id, int $page_id): array
{
    $greeting = sprintf(
        /* translators: %s: Slack user mention, z. B. <@U12345> */
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

    if ($page instanceof WP_Post && $page->post_status === 'publish') {
        // Viele Plugins (SEO, Related-Posts, Shortcodes) hängen sich in "the_content" ein
        // und erwarten dabei einen gültigen globalen $post. Da wir außerhalb des normalen
        // Loops laufen (Webhook-Request), müssen wir das explizit herstellen und danach
        // wieder sauber zurücksetzen, damit wir keine anderen Hooks/Requests beeinflussen.
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

    // Slack erlaubt max. 50 Blocks pro Nachricht.
    return array_slice($blocks, 0, 50);
}

/**
 * Öffnet die DM und verschickt die Willkommensnachricht.
 */
function swb_send_welcome_dm(string $user_id): void
{
    $options = SWB_Settings::get_options();

    if ($options['bot_token'] === '') {
        error_log('Slack Welcome Bot: kein Bot-Token konfiguriert.');
        return;
    }

    $client = new SWB_Slack_Client($options['bot_token']);
    $channel_id = $client->open_dm($user_id);

    if ($channel_id === null) {
        return;
    }

    $blocks = swb_build_blocks($user_id, (int) $options['rules_page_id']);

    $client->post_message($channel_id, __('Willkommen im Team! Hier sind die wichtigsten Regeln.', 'slack-welcome-bot'), $blocks);
}
