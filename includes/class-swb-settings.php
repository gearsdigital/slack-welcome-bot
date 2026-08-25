<?php

if (!defined('ABSPATH')) {
    exit;
}

class SWB_Settings
{
    private const OPTION_GROUP = 'swb_settings_group';
    public const OPTION_NAME = 'swb_settings';
    private const PAGE_SLUG = 'slack-welcome-bot';

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
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function get_options(): array
    {
        $defaults = [
            'bot_token' => '',
            'signing_secret' => '',
            'rules_page_id' => 0,
        ];

        return wp_parse_args(get_option(self::OPTION_NAME, []), $defaults);
    }

    public function add_settings_page(): void
    {
        add_options_page(
            __('Slack Welcome Bot', 'slack-welcome-bot'),
            __('Slack Welcome Bot', 'slack-welcome-bot'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section('swb_main_section', __('Slack-Zugangsdaten', 'slack-welcome-bot'), '__return_false', self::PAGE_SLUG);

        add_settings_field('bot_token', __('Bot User OAuth Token', 'slack-welcome-bot'), [$this, 'render_bot_token_field'], self::PAGE_SLUG, 'swb_main_section');
        add_settings_field('signing_secret', __('Signing Secret', 'slack-welcome-bot'), [$this, 'render_signing_secret_field'], self::PAGE_SLUG, 'swb_main_section');
        add_settings_field('rules_page_id', __('Seite mit den Regeln', 'slack-welcome-bot'), [$this, 'render_rules_page_field'], self::PAGE_SLUG, 'swb_main_section');
    }

    public function sanitize_settings($input): array
    {
        $existing = self::get_options();

        if (!is_array($input)) {
            return $existing;
        }

        return [
            'bot_token' => isset($input['bot_token']) && $input['bot_token'] !== ''
                ? sanitize_text_field($input['bot_token'])
                : $existing['bot_token'],
            'signing_secret' => isset($input['signing_secret']) && $input['signing_secret'] !== ''
                ? sanitize_text_field($input['signing_secret'])
                : $existing['signing_secret'],
            'rules_page_id' => isset($input['rules_page_id']) ? absint($input['rules_page_id']) : 0,
        ];
    }

    public function render_bot_token_field(): void
    {
        $options = self::get_options();
        $has_value = $options['bot_token'] !== '';

        printf(
            '<input type="password" name="%1$s[bot_token]" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
            esc_attr(self::OPTION_NAME),
            $has_value ? esc_attr__('•••••••• (bereits gespeichert, zum Ändern neu eingeben)', 'slack-welcome-bot') : 'xoxb-...'
        );
        echo '<p class="description">' . esc_html__('Bot User OAuth Token aus den Slack-App-Einstellungen (OAuth & Permissions).', 'slack-welcome-bot') . '</p>';
    }

    public function render_signing_secret_field(): void
    {
        $options = self::get_options();
        $has_value = $options['signing_secret'] !== '';

        printf(
            '<input type="password" name="%1$s[signing_secret]" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
            esc_attr(self::OPTION_NAME),
            $has_value ? esc_attr__('•••••••• (bereits gespeichert, zum Ändern neu eingeben)', 'slack-welcome-bot') : ''
        );
        echo '<p class="description">' . esc_html__('Signing Secret aus "Basic Information" in den Slack-App-Einstellungen.', 'slack-welcome-bot') . '</p>';
    }

    public function render_rules_page_field(): void
    {
        $options = self::get_options();

        wp_dropdown_pages([
            'name' => self::OPTION_NAME . '[rules_page_id]',
            'selected' => $options['rules_page_id'],
            'show_option_none' => __('– Seite auswählen –', 'slack-welcome-bot'),
            'option_none_value' => 0,
            // Also offer non-public pages (visibility "private") for selection - the
            // welcome DM reads the content directly from the database, regardless of
            // WordPress visibility or a page password.
            'post_status' => ['publish', 'private'],
        ]);

        echo '<p class="description">' . esc_html__('Der veröffentlichte Inhalt dieser WordPress-Seite wird 1:1 als Regeltext in der Willkommens-DM verwendet.', 'slack-welcome-bot') . '</p>';

        $this->render_restricted_page_notice((int) $options['rules_page_id']);
    }

    /**
     * Warns that a private/password-protected page is still sent unchanged via
     * Slack DM, despite its WordPress protection.
     */
    private function render_restricted_page_notice(int $page_id): void
    {
        if ($page_id <= 0) {
            return;
        }

        $page = get_post($page_id);

        if (!$page instanceof WP_Post) {
            return;
        }

        $reasons = [];

        if ($page->post_status === 'private') {
            $reasons[] = __('nur für angemeldete Benutzer mit entsprechenden Rechten sichtbar (Sichtbarkeit "Privat")', 'slack-welcome-bot');
        }

        if ($page->post_password !== '') {
            $reasons[] = __('passwortgeschützt', 'slack-welcome-bot');
        }

        if ($reasons === []) {
            return;
        }

        printf(
            '<p class="description" style="color:#b32d2e;">⚠️ %s</p>',
            esc_html(sprintf(
                /* translators: %s: reason(s), e.g. "password-protected" */
                __('Diese Seite ist %s. Der Inhalt wird trotzdem unverändert an neue Slack-Mitglieder gesendet – der WordPress-Schutz gilt nur für Website-Besucher, nicht für dieses Plugin.', 'slack-welcome-bot'),
                implode(__(' und ', 'slack-welcome-bot'), $reasons)
            ))
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $webhook_url = rest_url('slack-welcome-bot/v1/events');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Slack Welcome Bot', 'slack-welcome-bot'); ?></h1>

            <p><?php esc_html_e('Trage diese URL in den Slack-App-Einstellungen unter "Event Subscriptions → Request URL" ein:', 'slack-welcome-bot'); ?></p>
            <p>
                <input type="text" readonly value="<?php echo esc_url($webhook_url); ?>" class="large-text code" onclick="this.select();" />
            </p>

            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
