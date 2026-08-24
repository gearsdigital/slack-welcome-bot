<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers swb_build_blocks(), specifically which pages are considered a
 * valid content source (public, private, or password-protected).
 */
final class BuildBlocksTest extends TestCase
{
    protected function setUp(): void
    {
        SWB_Test_State::reset();
    }

    public function test_falls_back_when_no_page_is_selected(): void
    {
        $blocks = swb_build_blocks('U1', 0);

        $this->assertCount(2, $blocks);
        $this->assertStringContainsString('noch keine Regel-Seite', $blocks[1]['text']['text']);
    }

    public function test_uses_a_published_pages_content(): void
    {
        SWB_Test_State::$posts[1] = new WP_Post(1, 'publish', 'Regel A');

        $blocks = swb_build_blocks('U1', 1);

        $this->assertSame('Regel A', $blocks[1]['text']['text']);
    }

    public function test_uses_a_private_pages_content(): void
    {
        SWB_Test_State::$posts[2] = new WP_Post(2, 'private', 'Nur intern sichtbare Regel');

        $blocks = swb_build_blocks('U1', 2);

        $this->assertSame('Nur intern sichtbare Regel', $blocks[1]['text']['text']);
    }

    public function test_uses_a_password_protected_pages_content(): void
    {
        SWB_Test_State::$posts[3] = new WP_Post(3, 'publish', 'Passwortgeschützte Regel', 'geheim');

        $blocks = swb_build_blocks('U1', 3);

        $this->assertSame('Passwortgeschützte Regel', $blocks[1]['text']['text']);
    }

    public function test_falls_back_for_a_draft_page(): void
    {
        SWB_Test_State::$posts[4] = new WP_Post(4, 'draft', 'Noch nicht fertig');

        $blocks = swb_build_blocks('U1', 4);

        $this->assertStringContainsString('noch keine Regel-Seite', $blocks[1]['text']['text']);
    }
}
