<?php
class ETTest extends ShimmieWebTestCase {
	function testET() {
		$this->log_in_as_admin();
		$this->get_page("system_info");
		$this->assertTitle("System Info");
		$this->log_out();
	}
}
?>
