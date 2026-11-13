<?php
/**
 * Menus, options, media and terms.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate\Tests;

use Shakewell\NextjsRevalidate\Collector;

require_once __DIR__ . '/RevalidateTestCase.php';

/**
 * Everything that is not a post: its scope and what it names.
 */
final class SiteChangesTest extends RevalidateTestCase {

	/**
	 * Expected body for a site-wide change.
	 *
	 * @param string $scope Scope.
	 * @param string $type  Type, if any.
	 * @return array<string,string>
	 */
	private function wide( string $scope, string $type = '' ): array {
		return $this->with_source( array( 'scope' => $scope ) + ( '' !== $type ? array( 'type' => $type ) : array() ) );
	}

	public function test_a_menu_change_is_the_menus_scope(): void {
		$menu = wp_create_nav_menu( 'Main' );
		Collector::reset();
		wp_update_nav_menu_object( $menu, array( 'menu-name' => 'Main 2' ) );
		$this->assertSame( $this->wide( 'menus', 'menu' ), $this->flush() );
		do_action( 'wp_delete_nav_menu', $menu ); // The real deletion also rewrites the theme's menu locations, which is a site-wide option change.
		$this->assertSame( $this->wide( 'menus', 'menu' ), $this->flush() );
	}

	public function test_a_menu_change_together_with_a_page_is_everything(): void {
		$menu = wp_create_nav_menu( 'Main' );
		$id   = $this->make( 'book', 'moby', 'draft' );
		wp_update_nav_menu_object( $menu, array( 'menu-name' => 'Main 2' ) );
		wp_publish_post( $id );
		$this->assertSame( $this->wide( 'all' ), $this->flush() );
	}

	public function test_site_settings_and_theme_mods_are_everything(): void {
		update_option( 'blogname', 'A new name' );
		$this->assertSame( $this->wide( 'all', 'options' ), $this->flush() );
		set_theme_mod( 'header_color', '#fff' );
		$this->assertSame( $this->wide( 'all', 'options' ), $this->flush() );
	}

	public function test_the_options_list_is_a_setting(): void {
		update_option( 'my_custom_option', '1' );
		$this->assertNull( $this->flush() );
		add_filter( 'shakewell_nextjs_revalidate_options', static fn() => array( 'my_custom_option' ) );
		update_option( 'my_custom_option', '2' );
		$this->assertSame( $this->wide( 'all', 'options' ), $this->flush() );
		update_option( 'blogname', 'No longer listed' );
		$this->assertNull( $this->flush() );
	}

	public function test_unrelated_options_send_nothing(): void {
		update_option( 'cron_like_thing', 'x' );
		update_option( '_transient_x', 'y' );
		$this->assertNull( $this->flush() );
	}

	public function test_options_pages_are_everything_unless_configured_otherwise(): void {
		$_GET['page'] = 'site-settings';
		update_option( 'options_header_logo', '5' );
		$this->assertSame( $this->wide( 'all', 'options' ), $this->flush() );
		Collector::on_acf_save( 'options' );
		$this->assertNull( $this->flush(), 'an options page save that changed nothing sends nothing' );
	}

	public function test_option_effects_decide_per_field(): void {
		add_filter(
			'shakewell_nextjs_revalidate_option_effects',
			static fn() => array(
				'menus' => array( 'nav_', 'footer_menu_' ),
				'none'  => array( 'api_', 'sync_' ),
				'book'  => array( 'shelf_' ),
			)
		);
		$_GET['page'] = 'site-settings';
		update_option( 'options_nav_links_2_label', 'x' );
		$this->assertSame( $this->wide( 'menus', 'menu' ), $this->flush() );
		update_option( 'options_api_token', 'x' );
		update_option( 'options_sync_retries', '3' );
		$this->assertNull( $this->flush() );
		update_option( 'options_shelf_order', 'x' );
		$this->assertSame( $this->with_source( array( 'scope' => 'types', 'types' => array( 'book' ) ) ), $this->flush() ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		update_option( 'options_something_new', 'x' );
		$this->assertSame( $this->wide( 'all', 'options' ), $this->flush(), 'an unknown field is treated as site-wide' );
	}

	public function test_saves_from_ignored_options_pages_and_background_jobs_send_nothing(): void {
		add_filter( 'shakewell_nextjs_revalidate_ignored_option_pages', static fn() => array( 'search-synonyms' ) );
		$_GET['page'] = 'search-synonyms';
		update_option( 'options_synonyms_0_word', 'x' );
		$this->assertNull( $this->flush() );
		$_GET['page'] = 'site-settings';
		add_filter( 'wp_doing_cron', '__return_true' );
		update_option( 'options_header_logo', '6' );
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertNull( $this->flush() );
	}

	public function test_acf_saves_of_users_and_terms(): void {
		Collector::on_acf_save( 'user_3' );
		$this->assertNull( $this->flush() );
		$term = self::factory()->term->create( array( 'taxonomy' => 'topic' ) );
		Collector::reset();
		Collector::on_acf_save( "term_$term" );
		$this->assertSame( array( "term:$term" ), $this->flush()['items'] );
		Collector::on_acf_save( 'widget_5' );
		$this->assertSame( $this->wide( 'all' ), $this->flush(), 'an unknown ACF object is treated as site-wide' );
	}

	public function test_acf_comment_forms_send_nothing_and_menu_fields_are_the_menus_scope(): void {
		Collector::on_acf_save( 'comment_12' );
		$this->assertNull( $this->flush() );
		Collector::on_acf_save( 'menu_3' );
		$this->assertSame( $this->wide( 'menus', 'menu' ), $this->flush() );
		Collector::on_acf_save( 'nav_menu_item_12' );
		$this->assertSame( $this->wide( 'menus', 'menu' ), $this->flush() );
	}

	public function test_acf_saving_a_published_post_refreshes_it_and_a_draft_does_not(): void {
		$id = $this->make( 'book', 'moby' );
		Collector::on_acf_save( $id );
		$this->assertContains( '/books/moby/', $this->flush()['paths'] );
		$draft = $this->make( 'book', 'd', 'draft' );
		Collector::on_acf_save( $draft );
		$this->assertNull( $this->flush() );
	}

	public function test_editing_an_attachment_names_it(): void {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg' ) );
		Collector::reset();
		wp_update_post(
			array(
				'ID'           => $att,
				'post_excerpt' => 'caption',
			)
		);
		$this->assertSame(
			$this->with_source(
				array(
					'scope' => 'items',
					'items' => array( "media:$att" ),
					'type'  => 'media',
					'id'    => $att,
				)
			),
			$this->flush()
		);
	}

	public function test_alt_text_and_image_editor_saves_name_the_attachment(): void {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg' ) );
		Collector::reset();
		update_post_meta( $att, '_wp_attachment_image_alt', 'first' );
		update_post_meta( $att, '_wp_attachment_image_alt', 'second' );
		update_post_meta( $att, '_wp_attachment_metadata', array( 'width' => 1 ) );
		$this->assertSame( array( "media:$att" ), $this->flush()['items'] );
	}

	public function test_a_new_upload_shows_nowhere_but_a_later_edit_does(): void {
		$att = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		Collector::reset();
		// Core's order: the file meta is added first, then the metadata and the alt text.
		add_post_meta( $att, '_wp_attached_file', 'a.jpg' );
		add_post_meta( $att, '_wp_attachment_metadata', array( 'width' => 1 ) );
		add_post_meta( $att, '_wp_attachment_image_alt', 'alt' );
		Collector::on_media( $att );
		$this->assertNull( $this->flush() );
		$old = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		Collector::reset();
		add_post_meta( $old, '_wp_attachment_image_alt', 'alt' );
		$this->assertSame( array( "media:$old" ), $this->flush()['items'] );
	}

	public function test_deleting_an_attachment_names_it(): void {
		$att = self::factory()->attachment->create_object( array( 'file' => 'a.jpg' ) );
		Collector::reset();
		wp_delete_attachment( $att, true );
		$this->assertSame( array( "media:$att" ), $this->flush()['items'] );
	}

	public function test_a_term_of_an_unknown_taxonomy_still_refreshes(): void {
		register_taxonomy( 'brand_new', 'page' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'brand_new' ) );
		$this->assertSame( $this->with_source( array( 'scope' => 'items', 'items' => array( "term:$term" ) ) ), $this->flush() ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	public function test_a_hidden_taxonomy_still_refreshes_the_listing_of_its_post_types(): void {
		$term = self::factory()->term->create( array( 'taxonomy' => 'genre' ) );
		$this->assertSame(
			$this->with_source(
				array(
					'scope' => 'page',
					'paths' => array( '/books/' ),
					'items' => array( "term:$term" ),
				)
			),
			$this->flush()
		);
	}

	public function test_ignored_taxonomies_send_nothing(): void {
		self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->assertNotNull( $this->flush() );
		add_filter( 'shakewell_nextjs_revalidate_ignored_taxonomies', static fn() => array( 'genre', 'category' ) );
		self::factory()->term->create( array( 'taxonomy' => 'genre' ) );
		self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		wp_create_nav_menu( 'Not a page' );
		$this->assertNull( $this->flush(), 'the core menu taxonomy is always ignored' );
	}

	public function test_deleting_a_term_with_posts_changes_list_membership(): void {
		$book = $this->make( 'book', 'moby' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'topic' ) );
		wp_set_object_terms( $book, array( $term ), 'topic' );
		Collector::reset();
		wp_delete_term( $term, 'topic' );
		$body = $this->flush();
		$this->assertSame( array( "term:$term" ), $body['items'] );
		$this->assertSame( array( 'book' ), $body['types'] );

		$empty = self::factory()->term->create( array( 'taxonomy' => 'topic' ) );
		Collector::reset();
		wp_delete_term( $empty, 'topic' );
		$this->assertArrayNotHasKey( 'types', $this->flush() );
	}
}
