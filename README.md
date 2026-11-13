# Shakewell Cache Revalidation for Next.js

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Free, GPL-2.0-or-later WordPress plugin for headless sites. When an editor publishes, updates, unpublishes, trashes or deletes content, or changes a menu, a site setting, a term or a media item, it tells your Next.js front end which pages and content types to revalidate, with one signed request per save. Setup, every setting and the External services statement are in `readme.txt`.

Not affiliated with, endorsed by or sponsored by Vercel. "Next.js" is a trademark of Vercel, Inc., used here only to say which front end the plugin talks to.

## What it does

- Collects the changes of a PHP request and sends **one** signed `POST` at shutdown, after the editor's response is finished when PHP-FPM allows, so a save never waits for it and an error in it cannot break it.
- Fails closed: with no endpoint or secret it sends nothing.
- Never logs the secret or the request body. The log line has counts, scope, HTTP status, reason and event id.
- Retries an uncertain send up to three times through wp-cron, with the same event id and body, so the front end can treat it as a repeat.
- Names what changed in a body the front end can act on: `{"scope":"page","paths":[...],"items":[...],"types":[...]}`, `{"scope":"items"}`, `{"scope":"types"}`, `{"scope":"menus"}` or `{"scope":"all"}`, plus log-only detail.

## Quick start

In `wp-config.php` or the environment:

```php
define( 'SHAKEWELL_NEXTJS_REVALIDATE_URL', 'https://frontend.example/api/revalidate' );
define( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET', '<a random secret of at least 32 characters>' );
define( 'SHAKEWELL_NEXTJS_REVALIDATE_ENVIRONMENT', 'production' ); // part of the signed message, default wp_get_environment_type()
```

Then tell it which pages a content type also appears on:

```php
add_filter( 'shakewell_nextjs_revalidate_listings', fn() => array( 'book' => array( '/books/' ) ) );
```

The request is signed as `sha256=` plus the hex HMAC-SHA256 of `<environment>.<timestamp>.<event>.` followed by the exact body, with the headers `x-revalidate-timestamp`, `x-revalidate-event` and `x-revalidate-signature`. The full list of settings is in `readme.txt`.

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8922
npm test               # PHPUnit in the test container, fake HTTP, no network
composer lint
npm run plugin-check
```

## Release

1. Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, and add a changelog line.
2. Tag `vX.Y.Z` and push. The release workflow checks the three versions match and attaches `shakewell-nextjs-revalidate.zip` to a GitHub release.

## Made by Shakewell

This plugin is one of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

More from Shakewell:

- [Shakewell Activity Log](https://github.com/shakewellagency/shakewell-activity-log)
- [Shakewell Multiple Authors](https://github.com/shakewellagency/shakewell-authors)
- [Shakewell Clone Posts](https://github.com/shakewellagency/shakewell-clone-posts)
- [Shakewell Connector for Klaviyo and Gravity Forms](https://github.com/shakewellagency/shakewell-klaviyo-connector)
- [Shakewell Media Offload for Amazon S3](https://github.com/shakewellagency/shakewell-media-offload)
- [Shakewell Redirects](https://github.com/shakewellagency/shakewell-redirects)
- [Shakewell Two-way Relationships for ACF](https://github.com/shakewellagency/shakewell-relationship-sync)
- [Shakewell Single Sign-On (SAML)](https://github.com/shakewellagency/shakewell-saml-sso)
- [Shakewell Mailer for Amazon SES](https://github.com/shakewellagency/shakewell-ses-mailer)
- [Shakewell Sitemap](https://github.com/shakewellagency/shakewell-sitemap)
- [Shakewell Error Reporting for Sentry](https://github.com/shakewellagency/shakewell-sentry)
- [Shakewell plugin template](https://github.com/shakewellagency/shakewell-plugin-template), the starting point for every plugin
