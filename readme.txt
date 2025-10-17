=== Big Storm Staging ===
Contributors: bigstorm
Tags: robots, staging, seo
Requires at least: 5.2
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com. Additionally, returns HTTP 410 (Gone) for page requests from known search crawlers on staging domains. Can be removed once the site is launched to production.

== Description ==

Big Storm Staging is a simple plugin that prevents search engines from indexing staging sites. When activated on a domain ending with .greatbigstorm.com, it will:

* Override the default robots.txt content and add a "Disallow: /" directive to prevent crawling
* Return HTTP 410 (Gone) for page requests identified as coming from known search crawlers (e.g., Googlebot)

Key features:

* Automatically detects staging domains ending with .greatbigstorm.com
* Adds "Disallow: /" to robots.txt on staging domains
* Returns HTTP 410 (Gone) to search crawlers requesting pages on staging
* No configuration needed - works out of the box
* Only affects staging domains, leaving production sites untouched
* Can be safely removed once the site is launched to production

== Installation ==

1. Upload the `bigstorm-stage` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. No configuration needed - the plugin will automatically check the domain and modify robots.txt as needed

== Frequently Asked Questions ==

= How do I know if the plugin is working? =

Visit your site's robots.txt file (yourdomain.greatbigstorm.com/robots.txt) and verify it contains "Disallow: /". Additionally, when Googlebot or another known crawler requests any page, the server will respond with HTTP 410 (Gone).

= What does the 410 behavior do, and can I customize crawler detection? =

On staging domains ending in .greatbigstorm.com, page requests from known search crawlers (e.g., Googlebot, Bingbot, DuckDuckBot) get an HTTP 410 (Gone) response. This signals that the content should be removed from search results. The plugin still serves a robots.txt with `Disallow: /` so crawlers can read the rule.

You can customize the set of recognized crawlers using the `bigstorm_stage_crawlers` filter:

Example (in a small mu-plugin or your theme's functions.php):

```
add_filter( 'bigstorm_stage_crawlers', function( $bots ) {
	$bots[] = 'custombot';
	// Optionally remove defaults:
	// $bots = array_diff( $bots, array( 'ahrefsbot', 'semrushbot' ) );
	return $bots;
} );
```

= Will this affect my production site? =

No, the plugin only modifies robots.txt on domains ending with .greatbigstorm.com

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release
