<?php

if (!defined('ABSPATH')) {
    exit;
}

class SWB_Slack_Client
{
    private string $bot_token;

    public function __construct(string $bot_token)
    {
        $this->bot_token = $bot_token;
    }

    /**
     * Opens (or fetches) the DM channel with a user. Returns the channel ID or null on failure.
     */
    public function open_dm(string $user_id): ?string
    {
        $response = $this->call('conversations.open', ['users' => $user_id]);

        if (empty($response['ok'])) {
            error_log('Slack Welcome Bot: conversations.open fehlgeschlagen: ' . wp_json_encode($response));
            return null;
        }

        return $response['channel']['id'] ?? null;
    }

    /**
     * Sends a message with blocks to a channel/DM.
     */
    public function post_message(string $channel, string $fallback_text, array $blocks): bool
    {
        $response = $this->call('chat.postMessage', [
            'channel' => $channel,
            'text' => $fallback_text,
            'blocks' => wp_json_encode($blocks),
        ]);

        if (empty($response['ok'])) {
            error_log('Slack Welcome Bot: chat.postMessage fehlgeschlagen: ' . wp_json_encode($response));
            return false;
        }

        return true;
    }

    private function call(string $method, array $params): array
    {
        $response = wp_remote_post("https://slack.com/api/{$method}", [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->bot_token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $params,
        ]);

        if (is_wp_error($response)) {
            error_log("Slack Welcome Bot: HTTP-Fehler bei {$method}: " . $response->get_error_message());
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'invalid_json'];
    }
}
