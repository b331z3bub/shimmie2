<?php

declare(strict_types=1);

namespace Shimmie2;

class SetupTest extends ShimmiePHPUnitTestCase
{
    public function testNiceUrlsTest(): void
    {
        # XXX: this only checks that the text is "ok", to check
        # for a bug where it was coming out as "\nok"; it doesn't
        # check that niceurls actually work
        $this->get_page('nicetest');
        $this->assert_content("ok");
        $this->assert_no_content("\n");
    }

    public function testAuthAnon(): void
    {
        $this->get_page('setup');
        $this->assert_response(403);
        $this->assert_title("Permission Denied");
    }

    public function testAuthUser(): void
    {
        $this->log_in_as_user();
        $this->get_page('setup');
        $this->assert_response(403);
        $this->assert_title("Permission Denied");
    }

    public function testAuthAdmin(): void
    {
        $this->log_in_as_admin();
        $this->get_page('setup');
        $this->assert_title("Shimmie Setup");
        $this->assert_text("General");
    }

    public function testAdvanced(): void
    {
        $this->log_in_as_admin();
        $this->get_page('setup/advanced');
        $this->assert_title("Shimmie Setup");
        $this->assert_text(ImageConfig::THUMB_QUALITY);
    }
}
