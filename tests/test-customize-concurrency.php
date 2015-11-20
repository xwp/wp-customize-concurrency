<?php

class Test_Customize_Concurrency extends WP_UnitTestCase {

	/**
	 * @see customize_concurrency_php_version_error()
	 */
	function test_customize_concurrency_php_version_error() {
		ob_start();
		customize_concurrency_php_version_error();
		$buffer = ob_get_clean();
		$this->assertContains( '<div class="error">', $buffer );
	}

	/**
	 * @see customize_concurrency_php_version_text()
	 */
	function test_customize_concurrency_php_version_text() {
		$this->assertContains( 'Customize Concurrency plugin error:', customize_concurrency_php_version_text() );
	}
}
