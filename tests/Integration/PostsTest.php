<?php
/**
 * What a post change sends.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate\Tests;

use Shakewell\NextjsRevalidate\Collector;

require_once __DIR__ . '/RevalidateTestCase.php';

/**
 * Publish, update, move, unpublish, trash, delete and the cases that must send nothing.
 */
final class PostsTest extends RevalidateTestCase {

	public function test_publishing_sends_the_page_its_listing_and_its_type(): void {
		$id = $this->make( 'book', 'moby', 'draft' );
		wp_publish_post( $id );
		$this->assertSame(
			$this->with_source(
				array(
					'scope'    => 'page',
					'paths'    => array( '/books/moby/', '/books/' ),
					'items'    => array( 'path:/books/moby/' ),
					'types'    => array( 'book' ),
					'type'     => 'publish',
					'postType' => 'book',
					'id'       => $id,
					'slug'     => 'moby',
				)
			),
			$this->flush()
		);
	}

	public function test_several_changes_in_one_request_are_one_request_with_no_duplicates(): void {
		$id = $this->make( 'book', 'moby', 'draft' );
		wp_publish_post( $id );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Moby 2',
			)
		);
		$body = $this->flush();
		$this->assertSame( array( '/books/moby/', '/books/' ), $body['paths'] );
		$this->assertSame( 'publish', $body['type'], 'the strongest kind wins' );
	}

	public function test_a_listing_may_have_several_paths(): void {
		$id = $this->make( 'event', 'gig', 'draft' );
		wp_publish_post( $id );
		$this->assertSame( array( '/events/gig/', '/whats-on/', '/calendar/' ), $this->flush()['paths'] );
	}

	public function test_a_page_without_a_listing_sends_only_itself(): void {
		$id = $this->make( 'page', 'about', 'draft' );
		wp_publish_post( $id );
		$body = $this->flush();
		$this->assertSame( array( '/about/' ), $body['paths'] );
		$this->assertSame( array( 'path:/about/' ), $body['items'] );
		$this->assertArrayNotHasKey( 'types', $body );
	}

	public function test_a_plain_update_sends_no_type(): void {
		$id = $this->make( 'book', 'moby' );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'new text',
			)
		);
		$body = $this->flush();
		$this->assertSame( 'update', $body['type'] );
		$this->assertArrayNotHasKey( 'types', $body );
		$this->assertSame( array( 'path:/books/moby/' ), $body['items'] );
	}

	public function test_a_changed_slug_refreshes_the_old_and_new_address(): void {
		$id = $this->make( 'book', 'old-slug' );
		wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => 'new-slug',
			)
		);
		$body = $this->flush();
		$this->assertEqualsCanonicalizing( array( '/books/old-slug/', '/books/new-slug/', '/books/' ), $body['paths'] );
		$this->assertSame( 'slug_change', $body['type'] );
		$this->assertSame( 'old-slug', $body['oldSlug'] );
		$this->assertSame( 'new-slug', $body['slug'] );
		$this->assertSame( array( 'book' ), $body['types'] );
	}

	/**
	 * Classic editor order: the old address is read before the terms change, the address moves, post_updated follows.
	 */
	public function test_an_address_moved_by_other_data_is_found_through_the_remembered_old_address(): void {
		$id    = $this->make( 'book', 'moby' );
		$where = 'old';
		add_filter(
			'post_type_link',
			static function ( $link, $post ) use ( &$where ) {
				return 'book' === $post->post_type ? home_url( "/books/$where/moby/" ) : $link;
			},
			10,
			2
		);
		Collector::on_pre_update( $id );
		$where = 'new';
		$post  = get_post( $id );
		Collector::on_post_updated( $id, $post, $post );
		$body = $this->flush();
		$this->assertContains( '/books/old/moby/', $body['paths'] );
		$this->assertContains( '/books/new/moby/', $body['paths'] );
		$this->assertSame( 'slug_change', $body['type'] );
		$this->assertArrayNotHasKey( 'oldSlug', $body );

		// An ordinary edit afterwards remembers nothing of the move.
		Collector::on_pre_update( $id );
		Collector::on_post_updated( $id, $post, $post );
		$body = $this->flush();
		$this->assertSame( 'update', $body['type'] );
		$this->assertNotContains( '/books/old/moby/', $body['paths'] );
	}

	public function test_unpublishing_and_trashing_refresh_the_page(): void {
		$id = $this->make( 'book', 'moby' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$body = $this->flush();
		$this->assertSame( 'unpublish', $body['type'] );
		$this->assertContains( '/books/moby/', $body['paths'] );
		$this->assertSame( array( 'book' ), $body['types'] );

		$id = $this->make( 'book', 'second' );
		wp_trash_post( $id );
		$body = $this->flush();
		$this->assertSame( 'trash', $body['type'] );
		$this->assertContains( '/books/second/', $body['paths'] );
	}

	public function test_deleting_a_published_post_refreshes_it_and_a_draft_sends_nothing(): void {
		$id = $this->make( 'book', 'moby' );
		wp_delete_post( $id, true );
		$body = $this->flush();
		$this->assertSame( 'delete', $body['type'] );
		$this->assertContains( '/books/moby/', $body['paths'] );

		$draft = $this->make( 'book', 'draft-one', 'draft' );
		wp_delete_post( $draft, true );
		$this->assertNull( $this->flush() );
	}

	public function test_changes_nobody_sees_send_nothing(): void {
		$draft = $this->make( 'book', 'd', 'draft' );
		wp_update_post(
			array(
				'ID'         => $draft,
				'post_title' => 'Changed',
			)
		);
		$this->make( 'book', 'attachment-parent' );
		$note = self::factory()->post->create(
			array(
				'post_type'   => 'secret_note',
				'post_status' => 'publish',
			)
		);
		self::factory()->attachment->create_object(
			array(
				'file'           => 'a.jpg',
				'post_parent'    => 0,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertGreaterThan( 0, $note );
		$this->assertNull( $this->flush(), 'drafts, private types and new uploads send nothing' );
	}

	public function test_requests_from_the_front_end_itself_send_nothing(): void {
		add_filter( 'shakewell_nextjs_revalidate_skip_user_agent', static fn() => 'site-reader' );
		$_SERVER['HTTP_USER_AGENT'] = 'site-reader/1.0';
		$id                         = $this->make( 'book', 'moby', 'draft' );
		wp_publish_post( $id );
		$menu = wp_create_nav_menu( 'Menu A' );
		wp_update_nav_menu_object( $menu, array( 'menu-name' => 'Menu B' ) );
		$this->assertNull( $this->flush() );
		unset( $_SERVER['HTTP_USER_AGENT'] );
		wp_update_nav_menu_object( $menu, array( 'menu-name' => 'Menu C' ) );
		$this->assertNotNull( $this->flush(), 'the same change from an editor is sent' );
	}

	public function test_only_the_configured_post_types_trigger(): void {
		add_filter( 'shakewell_nextjs_revalidate_post_types', static fn() => array( 'book' ) );
		$event = $this->make( 'event', 'gig', 'draft' );
		wp_publish_post( $event );
		$this->assertNull( $this->flush() );
		$book = $this->make( 'book', 'moby', 'draft' );
		wp_publish_post( $book );
		$this->assertNotNull( $this->flush() );
	}

	public function test_a_shared_content_type_refreshes_everything(): void {
		add_filter( 'shakewell_nextjs_revalidate_shared_types', static fn() => array( 'event' ) );
		$id = $this->make( 'event', 'gig', 'draft' );
		wp_publish_post( $id );
		$this->assertSame( $this->with_source( array( 'scope' => 'all' ) ), $this->flush() );
	}

	public function test_a_title_change_of_a_menu_type_changes_its_membership(): void {
		add_filter( 'shakewell_nextjs_revalidate_menu_types', static fn() => array( 'book' ) );
		$id = $this->make( 'book', 'moby' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'A new title',
			)
		);
		$this->assertSame( array( 'book' ), $this->flush()['types'] );
		$page = $this->make( 'page', 'about' );
		wp_update_post(
			array(
				'ID'         => $page,
				'post_title' => 'A new title',
			)
		);
		$this->assertArrayNotHasKey( 'types', $this->flush() );
	}

	public function test_changed_terms_and_membership_meta_add_the_type(): void {
		$id = $this->make( 'book', 'moby' );
		wp_set_object_terms( $id, array( 'fiction' ), 'topic' );
		$this->flush();
		wp_set_object_terms( $id, array( 'fiction' ), 'topic' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'X',
			)
		);
		$this->assertArrayNotHasKey( 'types', $this->flush(), 'the same terms add nothing' );
		wp_set_object_terms( $id, array( 'poetry' ), 'topic' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Y',
			)
		);
		$this->assertSame( array( 'book' ), $this->flush()['types'] );

		add_filter( 'shakewell_nextjs_revalidate_membership_meta', static fn() => array( 'hidden' ) );
		update_post_meta( $id, 'other_meta', '1' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Z',
			)
		);
		$this->assertArrayNotHasKey( 'types', $this->flush() );
		update_post_meta( $id, 'hidden', '1' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'W',
			)
		);
		$this->assertSame( array( 'book' ), $this->flush()['types'] );
	}

	public function test_posts_that_show_another_post_without_linking_to_it_are_refreshed(): void {
		add_filter(
			'shakewell_nextjs_revalidate_related',
			static fn() => array(
				'book' => array(
					array(
						'post_type' => 'event',
						'meta_key'  => 'event_book',
					),
				),
			)
		);
		$book = $this->make( 'book', 'moby' );
		$gig  = $this->make( 'event', 'gig' );
		update_post_meta( $gig, 'event_book', serialize( array( (string) $book ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- how ACF stores a multi-select.
		$other = $this->make( 'event', 'other' );
		update_post_meta( $other, 'event_book', '9999' );
		Collector::reset();
		wp_update_post(
			array(
				'ID'           => $book,
				'post_content' => 'new',
			)
		);
		$body = $this->flush();
		$this->assertContains( '/events/gig/', $body['paths'] );
		$this->assertNotContains( '/events/other/', $body['paths'] );
		$this->assertArrayNotHasKey( 'types', $body );

		// A plain id is found too.
		update_post_meta( $other, 'event_book', (string) $book );
		Collector::reset();
		wp_update_post(
			array(
				'ID'           => $book,
				'post_content' => 'newer',
			)
		);
		$this->assertContains( '/events/other/', $this->flush()['paths'] );

		// An event update looks up nothing.
		Collector::reset();
		wp_update_post(
			array(
				'ID'         => $gig,
				'post_title' => 'T',
			)
		);
		$this->assertNotContains( '/events/other/', $this->flush()['paths'] );
	}

	public function test_too_many_related_posts_send_their_type_instead(): void {
		add_filter( 'shakewell_nextjs_revalidate_max_targets', static fn() => 2 );
		add_filter(
			'shakewell_nextjs_revalidate_related',
			static fn() => array(
				'book' => array(
					array(
						'post_type' => 'event',
						'meta_key'  => 'event_book',
					),
				),
			)
		);
		$book = $this->make( 'book', 'moby' );
		foreach ( array( 'a', 'b', 'c' ) as $slug ) {
			$e = $this->make( 'event', $slug );
			update_post_meta( $e, 'event_book', (string) $book );
		}
		Collector::reset();
		wp_update_post(
			array(
				'ID'           => $book,
				'post_content' => 'new',
			)
		);
		$body = $this->flush();
		$this->assertSame( array( 'event' ), $body['types'] );
		$this->assertNotContains( '/events/a/', $body['paths'] );
	}

	public function test_more_than_the_limit_is_one_request_for_everything(): void {
		add_filter( 'shakewell_nextjs_revalidate_max_targets', static fn() => 5 );
		$ids = array();
		foreach ( range( 1, 6 ) as $i ) {
			$ids[] = $this->make( 'book', "b$i", 'draft' );
		}
		foreach ( $ids as $id ) {
			wp_publish_post( $id );
		}
		$this->assertSame( $this->with_source( array( 'scope' => 'all' ) ), $this->flush() );
	}

	public function test_shutdown_is_hooked_once_per_request(): void {
		remove_all_actions( 'shutdown' );
		Collector::reset();
		$a = $this->make( 'book', 'a', 'draft' );
		$b = $this->make( 'book', 'b', 'draft' );
		wp_publish_post( $a );
		wp_publish_post( $b );
		global $wp_filter;
		$this->assertCount( 1, $wp_filter['shutdown']->callbacks[ PHP_INT_MAX ] );
	}
}
