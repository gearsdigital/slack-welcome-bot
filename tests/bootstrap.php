<?php
/**
 * Minimal test bootstrap.
 *
 * These tests target the plugin's logic directly, without booting WordPress.
 * Only the WP functions/classes the code under test actually calls are
 * stubbed below; anything hook-related (add_action, register_rest_route,
 * ...) never runs because it lives behind the classes' private
 * constructors, which these tests don't invoke.
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        return trim(strip_tags($text));
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data): string
    {
        return json_encode($data);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, array $defaults = []): array
    {
        return array_merge($defaults, is_array($args) ? $args : []);
    }
}

if (!function_exists('add_action')) {
    function add_action(...$args): bool
    {
        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return false;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return $response['body'] ?? '';
    }
}

/**
 * In-memory stand-in for WordPress options, transients, and the Slack
 * HTTP calls the plugin would otherwise make, so tests can configure and
 * inspect them without a database or real network access.
 */
class SWB_Test_State
{
    public static array $options = [];
    public static array $transients = [];
    public static array $http_calls = [];
    public static bool $slack_should_fail = false;

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$http_calls = [];
        self::$slack_should_fail = false;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return SWB_Test_State::$options[$name] ?? $default;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return SWB_Test_State::$transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration): bool
    {
        SWB_Test_State::$transients[$key] = $value;
        return true;
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = [])
    {
        SWB_Test_State::$http_calls[] = ['url' => $url, 'args' => $args];

        if (SWB_Test_State::$slack_should_fail) {
            return ['body' => json_encode(['ok' => false, 'error' => 'test_failure'])];
        }

        if (str_contains($url, 'conversations.open')) {
            return ['body' => json_encode(['ok' => true, 'channel' => ['id' => 'D123']])];
        }

        return ['body' => json_encode(['ok' => true])];
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        const CREATABLE = 'POST';
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;
        private int $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $body = '';
        private array $headers = [];

        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[$this->normalize($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[$this->normalize($key)] ?? null;
        }

        private function normalize(string $key): string
        {
            return strtolower(str_replace('-', '_', $key));
        }
    }
}

require_once __DIR__ . '/../includes/class-swb-html-converter.php';
require_once __DIR__ . '/../includes/class-swb-slack-client.php';
require_once __DIR__ . '/../includes/class-swb-settings.php';
require_once __DIR__ . '/../includes/class-swb-rest-controller.php';
