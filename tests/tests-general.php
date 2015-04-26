<?php


/**
 * @group ppp_general
 */
class Tests_General extends WP_UnitTestCase {
	public function setUp() {
		parent::setUp();

		$this->_post_id = $this->factory->post->create( array( 'post_title' => 'Test Post', 'post_type' => 'post', 'post_status' => 'publish' ) );
	}

	public function test_session_start() {
		$this->assertFalse( ppp_maybe_start_session() );
	}

	public function test_link_tracking() {
		global $ppp_share_settings;

		$this->assertFalse( ppp_link_tracking_enabled() );

		$ppp_share_settings['analytics'] = '1';
		$this->assertTrue( ppp_link_tracking_enabled() );
	}

	public function test_post_slug_by_id() {
		$this->assertEquals( 'test-post', ppp_get_post_slug_by_id( $this->_post_id ) );
	}

	public function test_text_tokens() {
		$expected = array(
			array( 'token' => 'post_title', 'description' => 'The title of the post being shared' ),
			array( 'token' => 'site_title', 'description' => 'The site title, from Settings > General' )
		);

		$this->assertEquals( $expected, ppp_get_text_tokens() );

	}

	public function test_entities_and_slashes() {

		$string = 'There\'s something missing here &amp; here';
		$expected = "There's something missing here & here";
		$this->assertEquals( $expected, ppp_entities_and_slashes( $string ) );

	}

	public function test_token_replacement() {
		$args = array( 'post_id' => $this->_post_id );
		$this->assertEquals( 'Test Post', ppp_replace_text_tokens( '{post_title}', $args ) );
		$this->assertEquals( 'Test Blog', ppp_replace_text_tokens( '{site_title}', $args ) );

		// Test replacing 2 in 1 string
		$this->assertEquals( 'Test Post on Test Blog', ppp_replace_text_tokens( '{post_title} on {site_title}', $args ) );

		// Test without the post ID to make sure it still returns something
		$this->assertEquals( '{post_title} on Test Blog', ppp_replace_text_tokens( '{post_title} on {site_title}', array() ) );
	}

	public function test_unique_link() {
		$link = get_post_permalink( $this->_post_id );
		$name = 'sharedate_0_' . $this->_post_id . '_tw';

		$unique_link = ppp_generate_unique_link( $link, $this->_post_id, $name );
		$this->assertEquals( 'http://example.org/?post_type=post&p=9&ppp=9-0', $unique_link );
	}

	public function test_google_utm_link() {
		$link = get_post_permalink( $this->_post_id );
		$name = 'sharedate_0_' . $this->_post_id . '_tw';

		$unique_link = ppp_generate_google_utm_link( $link, $this->_post_id, $name );
		$this->assertEquals( 'http://example.org/?post_type=post&p=10&utm_source=Twitter&utm_medium=social&utm_term=test-post&utm_content=0&utm_campaign=PostPromoterPro', $unique_link );
	}

	public function test_get_post_types() {
		$allowed_post_types = ppp_allowed_post_types();

		$this->assertInternalType( 'array', $allowed_post_types );
		$this->assertTrue( in_array( 'post', $allowed_post_types ) );

	}


}
