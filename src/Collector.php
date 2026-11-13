<?php
/**
 * Collects what changed during a request.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to content changes and remembers the affected paths, items and content types. Nothing is sent here:
 * Sender sends one request for the whole PHP request at shutdown, using what flush() returns.
 *
 * Every hook is guarded, so nothing in this class can break a save.
 */
final class Collector {

	/** Attachment meta that changes what a page shows. The image editor saves a new file through these and does not fire edit_attachment. */
	const MEDIA_META = array( '_wp_attachment_image_alt', '_wp_attached_file', '_wp_attachment_metadata' );

	/** Weakest first: when one post changes several times in a request, the strongest kind is reported. */
	const KINDS = array( 'update', 'publish', 'slug_change', 'unpublish', 'trash', 'delete' );

	/** Taxonomies WordPress itself manages and no page shows. Add more with the ignored_taxonomies setting. */
	const CORE_IGNORED_TAXONOMIES = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/** Core options whose change alters every page. theme_mods_* is matched by prefix. */
	const CORE_OPTIONS = array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'template', 'stylesheet' );

	/**
	 * Page paths to refresh.
	 *
	 * @var array<string,true>
	 */
	private static array $paths = array();

	/**
	 * What changed, for the front end to find the pages that show it: path:/x/, media:1, term:2.
	 *
	 * @var array<string,true>
	 */
	private static array $items = array();

	/**
	 * Post ids whose list membership may have changed (terms, parent, date, membership meta).
	 *
	 * @var array<int,true>
	 */
	private static array $membership = array();

	/**
	 * Attachments created in this request: nothing shows them yet.
	 *
	 * @var array<int,true>
	 */
	private static array $created = array();

	/**
	 * Content types whose change shows inside other pages.
	 *
	 * @var array<string,true>
	 */
	private static array $types = array();

	/**
	 * One per post id.
	 *
	 * @var array<int,array{kind:string,postType:string,slug:string,oldSlug:?string}>
	 */
	private static array $entries = array();

	/**
	 * Site-wide reasons: menu, options, media, other.
	 *
	 * @var array<string,true>
	 */
	private static array $site = array();

	/**
	 * Published post id => its address before this request's update, read before its terms change.
	 *
	 * @var array<int,string>
	 */
	private static array $old_paths = array();

	/**
	 * Posts whose address changed in this request: id => array( old address, new address ). Their children moved with them.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private static array $moved = array();

	/**
	 * Whether the shutdown hook is registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_hooked = false;

	/**
	 * Registers every hook.
	 */
	public static function boot(): void {
		add_action( 'pre_post_update', array( self::class, 'on_pre_update' ) );
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 10, 2 );
		add_action( 'acf/save_post', array( self::class, 'on_acf_save' ) );
		add_action( 'wp_update_nav_menu', array( self::class, 'on_menu' ) );
		add_action( 'wp_delete_nav_menu', array( self::class, 'on_menu' ) );
		add_action( 'edit_attachment', array( self::class, 'on_media' ) );
		add_action( 'delete_attachment', array( self::class, 'on_media' ) );
		add_action( 'set_object_terms', array( self::class, 'on_object_terms' ), 10, 6 );
		add_action( 'added_post_meta', array( self::class, 'on_meta_added' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'on_option' ) );
		add_action( 'updated_option', array( self::class, 'on_option' ) );
		add_action( 'deleted_option', array( self::class, 'on_option' ) );
		add_action( 'delete_term', array( self::class, 'on_term_deleted' ), 10, 5 );
		add_action( 'created_term', array( self::class, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( self::class, 'on_term' ), 10, 3 );
		add_action( Sender::RETRY_HOOK, array( Sender::class, 'retry' ), 10, 3 );
	}

	/**
	 * Runs a hook body so that no error can escape into the save. Only the class of the error is logged.
	 *
	 * @param callable $body Hook body.
	 */
	private static function guard( callable $body ): void {
		try {
			$body();
		} catch ( \Throwable $e ) {
			Sender::log( 'error in hook: ' . get_class( $e ) );
		}
	}

	/**
	 * Remembers a published post's address before the update. wp_insert_post sets categories after this hook and before
	 * post_updated (classic editor), and the block editor sets them after post_updated, so only this moment reliably
	 * still shows the old address. It is compared with the current one at send time.
	 *
	 * @param int $id Post id.
	 */
	public static function on_pre_update( $id ): void {
		self::guard(
			function () use ( $id ) {
				$post = get_post( (int) $id );
				if ( $post && 'publish' === $post->post_status && ! isset( self::$old_paths[ (int) $id ] ) ) {
					$path = self::path_of( $post );
					if ( null !== $path ) {
						self::$old_paths[ (int) $id ] = $path;
					}
				}
			}
		);
	}

	/**
	 * A post was updated: publish, unpublish, trash, address change or plain update.
	 *
	 * @param int      $id     Post id.
	 * @param \WP_Post $after  Post after the update.
	 * @param \WP_Post $before Post before the update.
	 */
	public static function on_post_updated( $id, $after, $before ): void {
		self::guard(
			function () use ( $after, $before ) {
				$was = 'publish' === $before->post_status;
				$is  = 'publish' === $after->post_status;
				if ( ( $was || $is ) && ( ( $before->post_date ?? '' ) !== ( $after->post_date ?? '' ) || (int) ( $before->post_parent ?? 0 ) !== (int) ( $after->post_parent ?? 0 ) ) ) {
					self::$membership[ (int) $after->ID ] = true;
				}
				if ( $was && $is && ( $before->post_title ?? '' ) !== ( $after->post_title ?? '' ) && in_array( $after->post_type, (array) Config::get( 'menu_types', array() ), true ) ) {
					self::$membership[ (int) $after->ID ] = true; // A menu lists it by title.
				}
				if ( ! $was && ! $is ) {
					return;
				}
				if ( $was && $is ) {
					$kind = self::path_of( $before ) !== self::path_of( $after ) ? 'slug_change' : 'update';
				} elseif ( $is ) {
					$kind = 'publish';
				} else {
					$kind = 'trash' === $after->post_status ? 'trash' : 'unpublish';
				}
				// The old object still has the old slug and parent, so an address change refreshes the old page too.
				if ( $was && 'slug_change' === $kind ) {
					self::$moved[ (int) $after->ID ] = array( (string) self::path_of( $before ), (string) self::path_of( $after ) );
				}
				if ( $was ) {
					self::queue_post( $before, $kind );
				}
				if ( $is ) {
					self::queue_post( $after, $kind, $was && 'slug_change' === $kind ? $before->post_name : null );
				}
			}
		);
	}

	/**
	 * Covers a brand-new post that is published at once (post_updated does not fire for inserts).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		self::guard(
			function () use ( $new_status, $old_status, $post ) {
				if ( 'publish' === $new_status && 'publish' !== $old_status ) {
					self::queue_post( $post, 'publish' );
				}
			}
		);
	}

	/**
	 * A post is about to be deleted. Only a published one matters.
	 *
	 * @param int           $id   Post id.
	 * @param \WP_Post|null $post Post.
	 */
	public static function on_delete( $id, $post = null ): void {
		self::guard(
			function () use ( $id, $post ) {
				$post = $post ? $post : get_post( $id );
				if ( $post && 'publish' === $post->post_status ) {
					self::queue_post( $post, 'delete' );
				}
			}
		);
	}

	/**
	 * Advanced Custom Fields saves fields after the post is written: a post id refreshes that page, a term id its listings,
	 * an options page arrives through on_option. Only runs when ACF is active.
	 *
	 * @param int|string $post_id ACF object id.
	 */
	public static function on_acf_save( $post_id ): void {
		self::guard(
			function () use ( $post_id ) {
				$post = is_numeric( $post_id ) ? get_post( (int) $post_id ) : null;
				if ( $post ) {
					if ( 'publish' === $post->post_status ) {
						self::queue_post( $post, 'update' );
					}
				} elseif ( in_array( $post_id, array( 'options', 'option' ), true ) ) {
					return; // Its changed fields arrive through on_option, so a save that changes nothing refreshes nothing.
				} elseif ( is_string( $post_id ) && 1 === preg_match( '/^term_(\d+)$/', $post_id, $m ) ) {
					self::queue_term( (int) $m[1], null );
				} elseif ( is_string( $post_id ) && ( 0 === strncmp( $post_id, 'user_', 5 ) || 0 === strncmp( $post_id, 'comment_', 8 ) ) ) {
					return; // No page shows users, and a comment form with fields must not refresh the site on every comment.
				} elseif ( is_string( $post_id ) && ( 0 === strncmp( $post_id, 'menu_', 5 ) || 0 === strncmp( $post_id, 'nav_menu_item_', 14 ) ) ) {
					self::queue_site( 'menu' );
				} else {
					// Widgets, blocks and anything unclassified: site-wide, the safe side. Options pages with a custom post_id store
					// their fields as <post_id>_<field>, which this plugin cannot tell from other options, so they are not covered.
					self::queue_site( 'other' );
				}
			}
		);
	}

	/**
	 * An option was written.
	 *
	 * @param string $name Option name.
	 */
	public static function on_option( $name ): void {
		self::guard(
			function () use ( $name ) {
				$name = (string) $name;
				if ( in_array( $name, (array) Config::get( 'options', self::CORE_OPTIONS ), true ) || 0 === strncmp( $name, 'theme_mods_', 11 ) ) {
					self::queue_site( 'options' );
				} elseif ( 0 === strncmp( $name, 'options_', 8 ) && self::is_options_page_save() ) {
					$effect = self::option_effect( substr( $name, 8 ) );
					if ( 'none' === $effect ) {
						return;
					}
					if ( 'menus' === $effect ) {
						self::queue_site( 'menu' );
					} elseif ( 'options' === $effect ) {
						self::queue_site( 'options' );
					} else {
						self::queue_type( $effect ); // Any other effect names the content type whose lists change.
					}
				}
			}
		);
	}

	/**
	 * What saving an ACF options field does, by field name without the "options_" prefix: "menus", "none", the name of a content
	 * type, or "options" (every page, the default so a new field is never missed). Configure with the option_effects setting,
	 * a map of effect => list of name prefixes.
	 *
	 * @param string $field Field name.
	 */
	public static function option_effect( string $field ): string {
		foreach ( (array) Config::get( 'option_effects', array() ) as $effect => $prefixes ) {
			foreach ( (array) $prefixes as $prefix ) {
				if ( '' !== (string) $prefix && 0 === strncmp( $field, (string) $prefix, strlen( (string) $prefix ) ) ) {
					return (string) $effect;
				}
			}
		}
		return 'options';
	}

	/**
	 * Options written by an editor's options page save, not by a scheduled job or a sync (those would refresh pages for nothing).
	 */
	private static function is_options_page_save(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared with a configured list, never stored or output.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! in_array( $page, (array) Config::get( 'ignored_option_pages', array() ), true );
	}

	/**
	 * A menu changed.
	 */
	public static function on_menu(): void {
		self::guard(
			function () {
				self::queue_site( 'menu' );
			}
		);
	}

	/**
	 * A term was created or renamed: the pages that show it, and the listings of the post types it belongs to.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_term( $term_id = 0, $tt_id = 0, $taxonomy = '' ): void {
		self::guard(
			function () use ( $term_id, $taxonomy ) {
				self::queue_term( (int) $term_id, (string) $taxonomy );
			}
		);
	}

	/**
	 * A deleted term that still had posts moves them to another term (or none): lists of their types may change membership.
	 *
	 * @param int    $term_id    Term id.
	 * @param int    $tt_id      Term taxonomy id.
	 * @param string $taxonomy   Taxonomy.
	 * @param mixed  $deleted    Deleted term.
	 * @param array  $object_ids Ids of the posts that had it.
	 */
	public static function on_term_deleted( $term_id = 0, $tt_id = 0, $taxonomy = '', $deleted = null, $object_ids = array() ): void {
		self::guard(
			function () use ( $term_id, $taxonomy, $object_ids ) {
				if ( self::queue_term( (int) $term_id, (string) $taxonomy ) && $object_ids ) {
					$tax = get_taxonomy( (string) $taxonomy );
					foreach ( (array) ( $tax->object_type ?? array() ) as $type ) {
						if ( isset( self::listings()[ $type ] ) ) {
							self::queue_type( $type );
						}
					}
				}
			}
		);
	}

	/**
	 * Queues a term's id and the listings of the post types that use its taxonomy.
	 *
	 * @param int         $term_id  Term id.
	 * @param string|null $taxonomy Taxonomy, looked up when empty.
	 */
	private static function queue_term( int $term_id, ?string $taxonomy ): bool {
		if ( self::skip_request() || $term_id < 1 ) {
			return false;
		}
		if ( ! $taxonomy ) {
			$term     = get_term( $term_id );
			$taxonomy = ( $term instanceof \WP_Term ) ? $term->taxonomy : '';
		}
		if ( in_array( $taxonomy, array_merge( self::CORE_IGNORED_TAXONOMIES, (array) Config::get( 'ignored_taxonomies', array() ) ), true ) ) {
			return false;
		}
		$tax                            = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : null;
		self::$items[ "term:$term_id" ] = true;
		foreach ( (array) ( $tax->object_type ?? array() ) as $type ) {
			foreach ( self::listings()[ $type ] ?? array() as $path ) {
				self::$paths[ $path ] = true;
			}
		}
		self::hook_shutdown();
		return true;
	}

	/**
	 * A post's categories, tags or other terms really changed: lists of its type may now include or drop it.
	 *
	 * @param int   $object_id Post id.
	 * @param array $terms     Terms.
	 * @param array $tt_ids    New term taxonomy ids.
	 * @param mixed $taxonomy  Taxonomy.
	 * @param mixed $append    Append flag.
	 * @param array $old_tt_ids Old term taxonomy ids.
	 */
	public static function on_object_terms( $object_id, $terms = array(), $tt_ids = array(), $taxonomy = '', $append = false, $old_tt_ids = array() ): void {
		self::guard(
			function () use ( $object_id, $taxonomy, $tt_ids, $old_tt_ids ) {
				$new = array_map( 'intval', (array) $tt_ids );
				$old = array_map( 'intval', (array) $old_tt_ids );
				if ( array_diff( $new, $old ) || array_diff( $old, $new ) ) {
					self::mark_membership( (int) $object_id, (string) $taxonomy );
				}
			}
		);
	}

	/**
	 * A published post may now be in or out of a list. Marks it, and queues the post itself so that a change made outside a
	 * post save (cron, import, REST meta update) is not lost. A save that follows queues the same post again, which costs nothing.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy when the change is a term change.
	 */
	private static function mark_membership( int $post_id, string $taxonomy = '' ): void {
		self::$membership[ $post_id ] = true;
		$post                         = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && ( '' === $taxonomy || is_object_in_taxonomy( $post->post_type, $taxonomy ) ) ) {
			self::queue_post( $post, 'update' );
		}
	}

	/**
	 * Alt text and the image file live in meta (the image editor saves only meta), and configured meta keys decide list membership.
	 *
	 * @param int    $meta_id   Meta id.
	 * @param int    $object_id Post id.
	 * @param string $key       Meta key.
	 */
	public static function on_meta( $meta_id, $object_id, $key ): void {
		self::guard(
			function () use ( $object_id, $key ) {
				if ( in_array( $key, self::MEDIA_META, true ) ) {
					self::on_media( (int) $object_id );
				} elseif ( in_array( $key, (array) Config::get( 'membership_meta', array() ), true ) ) {
					self::mark_membership( (int) $object_id );
				}
			}
		);
	}

	/**
	 * Core writes _wp_attached_file before add_attachment fires, so a new file's first meta is the only sign of an upload:
	 * from then on nothing shows it yet. The image editor changes the existing key (updated_post_meta), which on_meta still sends.
	 *
	 * @param int    $meta_id   Meta id.
	 * @param int    $object_id Post id.
	 * @param string $key       Meta key.
	 */
	public static function on_meta_added( $meta_id, $object_id, $key ): void {
		if ( '_wp_attached_file' === $key ) {
			self::guard(
				function () use ( $object_id ) {
					self::$created[ (int) $object_id ] = true;
				}
			);
			return;
		}
		self::on_meta( $meta_id, $object_id, $key );
	}

	/**
	 * An attachment was edited (alt text, caption, title), replaced or deleted: the pages whose payload shows it. A new upload shows nowhere.
	 *
	 * @param int $id Attachment id.
	 */
	public static function on_media( $id = 0 ): void {
		self::guard(
			function () use ( $id ) {
				$id = (int) $id;
				if ( $id > 0 && ! isset( self::$created[ $id ] ) && ! self::skip_request() ) {
					self::$items[ "media:$id" ] = true;
					self::hook_shutdown();
				}
			}
		);
	}

	/**
	 * Marks a content type: the front end refreshes the pages that list it.
	 *
	 * @param string $type Content type.
	 */
	private static function queue_type( string $type ): void {
		if ( ! self::skip_request() ) {
			self::$types[ $type ] = true;
			self::hook_shutdown();
		}
	}

	/**
	 * Marks a site-wide change.
	 *
	 * @param string $reason menu, options or other.
	 */
	private static function queue_site( string $reason ): bool {
		if ( self::skip_request() ) {
			return false;
		}
		self::$site[ $reason ] = true;
		self::hook_shutdown();
		return true;
	}

	/**
	 * Queues a public post's own address and the pages its type appears on.
	 *
	 * @param \WP_Post|null $post     Post.
	 * @param string        $kind     One of KINDS.
	 * @param string|null   $old_slug Previous slug after an address change.
	 */
	public static function queue_post( $post, string $kind = 'update', ?string $old_slug = null ): void {
		if ( self::skip_request() || ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'attachment' === $post->post_type ) {
			return;
		}
		$post_types = (array) Config::get( 'post_types', array() );
		if ( $post_types && ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}
		if ( in_array( $post->post_type, (array) Config::get( 'shared_types', array() ), true ) ) {
			self::queue_site( 'other' );
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$own = self::path_of( $post );
		if ( null === $own ) {
			return;
		}
		foreach ( array_merge( array( $own ), self::listings()[ $post->post_type ] ?? array() ) as $path ) {
			self::$paths[ $path ] = true;
		}
		self::$items[ "path:$own" ] = true; // Every card, tile, breadcrumb or link to it.
		$id                         = (int) $post->ID;
		$strongest                  = isset( self::$entries[ $id ] ) ? array_search( self::$entries[ $id ]['kind'], self::KINDS, true ) : -1;
		if ( array_search( $kind, self::KINDS, true ) >= $strongest ) {
			self::$entries[ $id ] = array(
				'kind'     => $kind,
				'postType' => $post->post_type,
				'slug'     => (string) $post->post_name,
				'oldSlug'  => $old_slug,
			);
		}
		self::hook_shutdown();
	}

	/**
	 * The listings setting: content type => pages it also appears on, besides its own address. Its keys are also the content
	 * types sent as "types".
	 *
	 * @return array<string,string[]>
	 */
	private static function listings(): array {
		return (array) Config::get( 'listings', array() );
	}

	/**
	 * The post's address as WordPress builds it. Null if it has no pretty address.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function path_of( $post ): ?string {
		$url = get_permalink( $post );
		if ( ! is_string( $url ) || false !== strpos( $url, '?' ) ) {
			return null;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return self::valid_path( $path ) ? $path : null;
	}

	/**
	 * The front end's rule: one leading /, no query, fragment, backslash or whitespace, at most 200 characters.
	 *
	 * @param mixed $path Candidate path.
	 */
	public static function valid_path( $path ): bool {
		return is_string( $path ) && strlen( $path ) <= 200 && 1 === preg_match( '#^/(?!/)[^\s?\\#\\\\]*$#', $path );
	}

	/**
	 * Requests that must not trigger a send: setup, and the front end's own reads (matched by User-Agent prefix).
	 */
	private static function skip_request(): bool {
		$prefix = (string) Config::get( 'skip_user_agent' );
		$agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return wp_installing() || ( '' !== $prefix && 0 === strncmp( $agent, $prefix, strlen( $prefix ) ) );
	}

	/**
	 * Registers the single send at the end of the request.
	 */
	private static function hook_shutdown(): void {
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			// Last, so any page cache has moved its generation on first and the front end never re-reads stale data.
			add_action( 'shutdown', array( Sender::class, 'send' ), PHP_INT_MAX );
		}
	}

	/**
	 * Queues paths by hand (for the command line).
	 *
	 * @param string[] $paths Page paths.
	 * @param bool     $all   Refresh every page.
	 */
	public static function queue_manual( array $paths, bool $all ): void {
		if ( $all ) {
			self::$site['other'] = true;
		}
		foreach ( $paths as $path ) {
			self::$paths[ $path ] = true;
		}
	}

	/**
	 * Works out what to send, empties the collector and returns it. Null when there is nothing to send.
	 *
	 * @return array{body:string,count:int,scope:string,reasons:string}|null
	 */
	public static function flush(): ?array {
		self::add_moved_posts();
		self::add_moved_children();
		self::add_membership_types();
		self::add_related_posts();
		$body    = self::body();
		$count   = count( self::$paths ) + count( self::$items );
		$kinds   = array_diff( array_map( fn( $key ) => strstr( $key, ':', true ), array_keys( self::$items ) ), array( 'path' ) ); // Media, term.
		$reasons = implode( ',', array_slice( array_unique( array_merge( array_column( self::$entries, 'postType' ), array_keys( self::$site ), $kinds ) ), 0, 5 ) );
		self::reset();
		if ( null === $body ) {
			return null;
		}
		return array(
			'body'    => $body,
			'count'   => $count,
			'scope'   => json_decode( $body, true )['scope'],
			'reasons' => $reasons,
		);
	}

	/**
	 * Forgets everything collected.
	 */
	public static function reset(): void {
		self::$paths           = array();
		self::$items           = array();
		self::$types           = array();
		self::$entries         = array();
		self::$site            = array();
		self::$old_paths       = array();
		self::$moved           = array();
		self::$membership      = array();
		self::$created         = array();
		self::$shutdown_hooked = false;
	}

	/**
	 * The most paths or items sent individually. More than this is sent as scope "all".
	 */
	private static function max_targets(): int {
		return max( 1, (int) Config::get( 'max_targets', 100 ) );
	}

	/**
	 * The JSON body for what was collected, or null if nothing.
	 * Pages and items only: scope page (paths, items, types), items or types. A menu alone: scope menus.
	 * Anything site-wide, or too many pages or items: scope all.
	 */
	private static function body(): ?string {
		$site    = array_keys( self::$site );
		$max     = self::max_targets();
		$body    = array( 'scope' => 'all' );
		$precise = ! $site && ( self::$paths || self::$items || self::$types ) && count( self::$paths ) <= $max && count( self::$items ) <= $max;
		if ( $precise ) {
			$body['scope'] = self::$paths ? 'page' : ( self::$items ? 'items' : 'types' );
			if ( self::$paths ) {
				$body['paths'] = array_keys( self::$paths );
			}
			if ( self::$items ) {
				$body['items'] = array_keys( self::$items );
			}
			if ( self::$types ) {
				$body['types'] = array_keys( self::$types );
			}
			if ( 1 === count( self::$entries ) ) { // Detail for the log only, when it is about one post.
				$id    = array_key_first( self::$entries );
				$e     = self::$entries[ $id ];
				$body += array(
					'type'     => $e['kind'],
					'postType' => $e['postType'],
					'id'       => $id,
				);
				foreach ( array(
					'slug'    => $e['slug'],
					'oldSlug' => $e['oldSlug'],
				) as $name => $slug ) {
					if ( null !== $slug && 1 === preg_match( '/^[A-Za-z0-9_%.-]{1,200}$/', $slug ) ) {
						$body[ $name ] = $slug;
					}
				}
			} elseif ( ! self::$entries && 1 === count( preg_grep( '/^media:/', array_keys( self::$items ) ) ) ) {
				$media = preg_grep( '/^media:/', array_keys( self::$items ) );
				$body += array(
					'type' => 'media',
					'id'   => (int) substr( reset( $media ), 6 ),
				);
			}
		} elseif ( array( 'menu' ) === $site && ! self::$paths && ! self::$items && ! self::$types ) {
			$body = array(
				'scope' => 'menus',
				'type'  => 'menu',
			);
		} elseif ( ! $site && ! self::$paths && ! self::$items && ! self::$types ) {
			return null;
		} elseif ( 1 === count( $site ) && ! self::$paths && in_array( $site[0], array( 'options', 'media' ), true ) ) {
			$body['type'] = $site[0];
		}
		$body['source'] = (string) Config::get( 'source', 'shakewell-nextjs-revalidate' );
		return wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * A queued, still published post whose address is not the one it had before: refresh the old address too.
	 */
	private static function add_moved_posts(): void {
		foreach ( self::$old_paths as $id => $old ) {
			$post = isset( self::$entries[ $id ] ) ? get_post( $id ) : null;
			$now  = $post && 'publish' === $post->post_status ? self::path_of( $post ) : null;
			if ( null === $now || $now === $old || ! self::valid_path( $old ) ) {
				continue;
			}
			self::$moved[ $id ]         = array( $old, $now );
			self::$paths[ $old ]        = true;
			self::$paths[ $now ]        = true;
			self::$items[ "path:$old" ] = true;
			self::$items[ "path:$now" ] = true;
			if ( array_search( self::$entries[ $id ]['kind'], self::KINDS, true ) < array_search( 'slug_change', self::KINDS, true ) ) {
				self::$entries[ $id ]['kind'] = 'slug_change';
			}
			if ( basename( $old ) !== self::$entries[ $id ]['slug'] && null === self::$entries[ $id ]['oldSlug'] ) {
				self::$entries[ $id ]['oldSlug'] = basename( $old );
			}
		}
	}

	/**
	 * A hierarchical post that moved takes its children with it: /parent/child/ becomes /renamed/child/. Refreshes both
	 * addresses of each published descendant. More than the limit: everything.
	 */
	private static function add_moved_children(): void {
		$max = self::max_targets();
		foreach ( self::$moved as $id => list( $old, $now ) ) {
			$post = get_post( $id );
			if ( ! $post || ! is_post_type_hierarchical( $post->post_type ) || '' === $old || '' === $now ) {
				continue;
			}
			$children = get_pages(
				array(
					'child_of'    => $id,
					'post_type'   => $post->post_type,
					'post_status' => 'publish',
					'number'      => $max + 1,
				)
			);
			if ( count( $children ) > $max ) {
				self::$site['other'] = true;
				return;
			}
			foreach ( $children as $child ) {
				$new = self::path_of( $child );
				if ( null === $new || 0 !== strncmp( $new, $now, strlen( $now ) ) ) {
					continue;
				}
				$was                        = $old . substr( $new, strlen( $now ) );
				self::$paths[ $was ]        = true;
				self::$paths[ $new ]        = true;
				self::$items[ "path:$was" ] = true;
				self::$items[ "path:$new" ] = true;
			}
		}
	}

	/**
	 * Lists of a post type may have gained or lost a member when an item appears, goes, moves, or has its terms, date, parent
	 * or membership meta changed. A plain update only changes the places that already show it.
	 */
	private static function add_membership_types(): void {
		foreach ( self::$entries as $id => $e ) {
			$lists = isset( self::listings()[ $e['postType'] ] ) || in_array( $e['postType'], (array) Config::get( 'menu_types', array() ), true );
			if ( $lists && ( 'update' !== $e['kind'] || isset( self::$membership[ $id ] ) ) ) {
				self::$types[ $e['postType'] ] = true;
			}
		}
	}

	/**
	 * Posts that show another post's data without linking to it. The related setting maps a source post type to a list of
	 * { post_type, meta_key }: when source posts change, the published posts of post_type whose meta_key holds one of their
	 * ids (a plain id, or a serialised list as Advanced Custom Fields stores it) are refreshed. This is one query per rule,
	 * however many posts changed. Too many changed posts, or too many results: the whole related type instead.
	 */
	private static function add_related_posts(): void {
		$related = (array) Config::get( 'related', array() );
		$max     = self::max_targets();
		$paths   = array();
		$ids_of  = array();
		foreach ( self::$entries as $id => $e ) {
			$ids_of[ $e['postType'] ][] = (int) $id;
		}
		foreach ( $ids_of as $source => $ids ) {
			foreach ( (array) ( $related[ $source ] ?? array() ) as $rule ) {
				if ( empty( $rule['post_type'] ) || empty( $rule['meta_key'] ) ) {
					continue;
				}
				if ( count( $ids ) > $max ) {
					self::$types[ $rule['post_type'] ] = true;
					continue;
				}
				$list  = implode( '|', $ids );
				$found = get_posts(
					array(
						'post_type'        => $rule['post_type'],
						'post_status'      => 'publish',
						'numberposts'      => $max + 1,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => false,
						'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup per rule.
							array(
								'key'     => $rule['meta_key'],
								'value'   => '^(' . $list . ')$|"(' . $list . ')"',
								'compare' => 'REGEXP',
							),
						),
					)
				);
				if ( count( $found ) > $max ) {
					self::$types[ $rule['post_type'] ] = true; // Too many to list: do not build the paths.
					continue;
				}
				foreach ( $found as $related_id ) {
					$post = get_post( $related_id );
					$path = $post ? self::path_of( $post ) : null;
					if ( null !== $path ) {
						$paths[ $path ] = $rule['post_type'];
					}
				}
			}
		}
		if ( count( self::$paths + $paths ) > $max ) {
			foreach ( array_unique( $paths ) as $type ) {
				self::$types[ $type ] = true;
			}
			return;
		}
		self::$paths += array_fill_keys( array_keys( $paths ), true );
	}
}
