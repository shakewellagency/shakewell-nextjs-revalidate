=== Shakewell Cache Revalidation for Next.js ===
Contributors: shakewellagency
Tags: headless, nextjs, cache, revalidate, api
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tells your headless Next.js front end which pages to revalidate when content changes. One signed request per save, and off until configured.

== Description ==

For sites that use WordPress as a headless CMS behind a Next.js front end. When an editor publishes, updates, unpublishes, trashes or deletes content, or changes a menu, a site setting, a term or a media item, the plugin works out which pages are affected and sends your front end one signed request. Your front end then refreshes only those pages.

This plugin is not affiliated with, endorsed by or sponsored by Vercel. "Next.js" is a trademark of Vercel, Inc., used only to say which front end the plugin talks to.

* Off unless an endpoint (https) and a secret (at least 32 characters) are set. With either missing it does nothing.
* One request for the whole PHP request, however many things changed. Over a configurable limit it sends "refresh everything" instead.
* Sent after the editor's response is finished when PHP-FPM allows, so saving never waits for it and an error in it cannot break the save.
* An uncertain result (no answer, a server error or the rate limit) is retried up to three times through wp-cron, with the same event id and body so your front end can treat it as a repeat.
* The secret is never sent, stored or logged. The log line holds counts, scope, HTTP status, reason and event id only.
* No settings page. Configuration is constants or environment variables, plus filters.

= What is sent =

A JSON body, in one of these shapes:

* `{"scope":"page","paths":["/books/a/","/books/"],"items":["path:/books/a/"],"types":["book"]}`: pages to refresh. "paths" are the item's own address and the listings of its type, "items" name what changed (`path:/x/`, `media:12`, `term:5`) so the front end can refresh the pages whose data shows it, "types" mean a list of that content type may have gained or lost a member.
* `{"scope":"items","items":[...]}` and `{"scope":"types","types":[...]}`.
* `{"scope":"menus"}` for a menu change alone, and `{"scope":"all"}` for anything site-wide.

Log-only detail (`type`, `postType`, `id`, `slug`, `oldSlug`, `source`) is added when the change is about one post or one media item.

Headers: `x-revalidate-timestamp`, `x-revalidate-event` and `x-revalidate-signature`. The signature is `sha256=` plus the hex HMAC-SHA256, keyed with the secret, of `<environment>.<timestamp>.<event>.` followed by the exact body. The environment is part of the signed message so a staging message can never be replayed on production. Your front end must check the signature, and that the timestamp is recent.

= External services =

This plugin sends requests to the revalidation endpoint you configure, which is your own Next.js site or the host that serves it. It sends nothing anywhere else, and nothing until you set the endpoint and the secret.

* What is sent: the JSON body above (page paths, item names, content type names, the kind of change, a post id and slug), the event id, a timestamp and a signature. No post content, no personal data and no secret.
* When: once at the end of a request in which content changed, and again for a retry.
* Terms and privacy: those of the service you point it at.

== Installation ==

1. Install and activate the plugin (upload the release zip, or `composer require` it).
2. Set the endpoint and secret in `wp-config.php`, above the line "That's all, stop editing", or in the server environment. The same secret must be set on your front end:

`define( 'SHAKEWELL_NEXTJS_REVALIDATE_URL', 'https://frontend.example/api/revalidate' );`
`define( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET', 'a-random-secret-of-at-least-32-characters' );`

3. Tell it which pages each content type also appears on (see Filters), save a post and check your front end.

= Settings =

Each can be a PHP constant `SHAKEWELL_NEXTJS_REVALIDATE_<NAME>` (upper case) or an environment variable of the same name. A constant wins over an environment variable. Every setting then passes through the filter `shakewell_nextjs_revalidate_<name>` (lower case), which has the last word. In an environment variable a list is comma-separated and a map is JSON.

* `URL`: the https endpoint. Required.
* `SECRET`: the shared secret, at least 32 characters. Required.
* `ENVIRONMENT`: the label that is part of the signed message. Default: the WordPress environment type (`wp_get_environment_type()`).
* `TIMEOUT`: seconds to wait for the answer when the response was finished first. Default 10. A request that does not wait uses at most 2.
* `HEADER_PREFIX`: header name prefix. Default `x-revalidate`.
* `SOURCE`: label added to the body, for your logs. Default `shakewell-nextjs-revalidate`.
* `MAX_TARGETS`: the most paths (and the most items) sent individually; more is sent as scope "all". Default 100.
* `POST_TYPES`: list of post types that trigger. Default: every public post type.
* `LISTINGS`: map of post type to the list of pages it also appears on, for example `{"book":["/books/"]}`. These keys are also the content types sent as "types".
* `SHARED_TYPES`: list of post types shown inside many pages. Any change to one refreshes everything.
* `IGNORED_TAXONOMIES`: list of taxonomies that never trigger, besides the ones WordPress manages itself. Every other taxonomy refreshes the pages that show its terms.
* `OPTIONS`: list of option names whose change refreshes everything. Default: site title, tagline, front page settings, permalink structure, template and stylesheet. Theme settings (`theme_mods_*`) always count.
* `OPTION_EFFECTS`: for sites using Advanced Custom Fields options pages, a map of effect to a list of field name prefixes (without the `options_` prefix). The effect is `menus`, `none`, or the name of a content type whose lists change. Any other field refreshes everything, so a new one is never missed.
* `IGNORED_OPTION_PAGES`: list of admin page slugs whose options saves refresh nothing.
* `MENU_TYPES`: list of post types a menu lists by title, so a title change counts as a membership change.
* `MEMBERSHIP_META`: list of post meta keys that decide whether an item appears in a list. A change adds the item's type to "types".
* `RELATED`: map of post type to a list of `{"post_type":"x","meta_key":"y"}`. When a post of that type changes, the published posts of `post_type` whose meta `meta_key` holds its id (a plain id, or a serialised list as Advanced Custom Fields stores it) are refreshed too. Too many: their whole type is sent instead.
* `SKIP_USER_AGENT`: requests whose User-Agent starts with this never trigger a send. Set it to your front end's own User-Agent so its REST reads cannot cause a loop. Default: none.

= Filters =

* `shakewell_nextjs_revalidate_<setting>`: see Settings. For example `add_filter( 'shakewell_nextjs_revalidate_listings', fn() => array( 'book' => array( '/books/' ) ) );`
* `shakewell_nextjs_revalidate_signature` ( $signature, $secret, $environment, $timestamp, $event, $body ): replace the signature, to sign another way. Your front end must verify the same way.

= Command line =

`wp shakewell-nextjs-revalidate /books/a/ /books/` refreshes those pages, `wp shakewell-nextjs-revalidate --all` refreshes everything. Bulk media jobs run from the command line that touch more than the limit are sent as scope "all".

== Frequently Asked Questions ==

= Does it work without Advanced Custom Fields? =

Yes. The ACF hooks only fire when ACF is active.

= What if the front end is down? =

The save is never affected. The request is retried three times through wp-cron. If it still fails, your front end's own cache expiry is the fallback.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
