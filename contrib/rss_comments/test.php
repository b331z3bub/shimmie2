<?php
class RSSCommentsTest extends ShimmieWebTestCase {
    function testImageFeed() {
        $this->get_page('rss/comments');
		$this->assertMime("application/rss+xml");
		$this->assertNoText("Exception");

		# FIXME: test that there are some comments here
    }
}
?>
