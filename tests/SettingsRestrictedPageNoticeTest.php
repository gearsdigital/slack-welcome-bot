<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the warning SWB_Settings::render_rules_page_field() shows when the
 * selected rules page isn't publicly reachable (private visibility and/or
 * a page password), since the bot still sends its content regardless.
 */
final class SettingsRestrictedPageNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        SWB_Test_State::reset();
    }

    private function render(): string
    {
        ob_start();
        SWB_Settings::instance()->render_rules_page_field();
        return ob_get_clean();
    }

    public function test_no_notice_when_no_page_is_selected(): void
    {
        $this->assertStringNotContainsString('⚠️', $this->render());
    }

    public function test_no_notice_for_a_regular_public_page(): void
    {
        SWB_Test_State::$options['swb_settings'] = ['rules_page_id' => 1];
        SWB_Test_State::$posts[1] = new WP_Post(1, 'publish', 'Regel A');

        $this->assertStringNotContainsString('⚠️', $this->render());
    }

    public function test_shows_a_notice_for_a_private_page(): void
    {
        SWB_Test_State::$options['swb_settings'] = ['rules_page_id' => 2];
        SWB_Test_State::$posts[2] = new WP_Post(2, 'private', 'Interne Regel');

        $html = $this->render();

        $this->assertStringContainsString('⚠️', $html);
        $this->assertStringContainsString('Sichtbarkeit', $html);
    }

    public function test_shows_a_notice_for_a_password_protected_page(): void
    {
        SWB_Test_State::$options['swb_settings'] = ['rules_page_id' => 3];
        SWB_Test_State::$posts[3] = new WP_Post(3, 'publish', 'Regel', 'geheim');

        $html = $this->render();

        $this->assertStringContainsString('⚠️', $html);
        $this->assertStringContainsString('passwortgesch', $html);
    }

    public function test_combines_both_reasons_when_both_apply(): void
    {
        SWB_Test_State::$options['swb_settings'] = ['rules_page_id' => 4];
        SWB_Test_State::$posts[4] = new WP_Post(4, 'private', 'Regel', 'geheim');

        $html = $this->render();

        $this->assertStringContainsString('Sichtbarkeit', $html);
        $this->assertStringContainsString('passwortgesch', $html);
    }
}
