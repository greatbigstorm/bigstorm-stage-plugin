=== Big Storm Staging ===
Contributors: greatbigstorm
Tags: staging, robots, seo, development
Requires at least: 5.2
Tested up to: 6.7
Stable tag: 1.1.1
Requires PHP: 7.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com. Additionally, returns HTTP 410 (Gone) for page requests from known search crawlers on staging domains. Can be removed once the site is launched to production.

== Description ==

Big Storm Staging is a simple plugin that prevents search engines from indexing staging sites. When activated on a domain ending with .greatbigstorm.com, it will:

* Return HTTP 410 (Gone) for page requests identified as coming from known search crawlers (e.g., Googlebot)
* Enable "Discourage search engines from indexing this site" in Reading Settings on activation
* Optionally add a "Disallow: /" directive to robots.txt to prevent crawling
* Configure a custom staging domain match (a full domain like "staging.example.com" or a suffix like ".greatbigstorm.com") in Settings → Big Storm Staging (default: .greatbigstorm.com)

Key features:

* Automatically detects staging domains ending with .greatbigstorm.com
* Enables WordPress search engine discouragement on activation
* Returns HTTP 410 (Gone) to search crawlers requesting pages on staging
* Optional robots.txt blocking (disabled by default to allow 410 responses)
* No configuration needed - works out of the box
* Only affects staging domains, leaving production sites untouched
* Can be safely removed once the site is launched to production

== Installation ==

1. Upload the `bigstorm-stage` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. No configuration needed - the plugin will automatically send HTTP 410 responses to crawlers on staging domains
4. Optional: Go to Settings → Big Storm Staging to configure the staging domain match or enable robots.txt blocking

== Frequently Asked Questions ==

= How do I know if the plugin is working? =

When Googlebot or another known crawler requests any page on your staging domain, the server will respond with HTTP 410 (Gone). Optionally, enable the "Block crawlers with robots.txt" setting in Settings → Big Storm Staging to also add "Disallow: /" to your robots.txt file.

= Should I enable robots.txt blocking? =

If your staging site has never been indexed by search engines, you can enable robots.txt blocking as an additional safeguard. However, if your staging site is already indexed in Google Search Console, keep this setting disabled. This allows Googlebot to receive HTTP 410 responses, which signals Google to remove the pages from its search index. A robots.txt "Disallow" directive prevents crawlers from accessing pages entirely, so they cannot receive the 410 status.

= What does the 410 behavior do, and can I customize crawler detection? =

On staging domains, page requests from known search crawlers (e.g., Googlebot, Bingbot, DuckDuckBot) get an HTTP 410 (Gone) response. This signals that the content should be removed from search results.

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

= Can I change which domains are treated as staging? =

Yes. Go to Settings → Big Storm Staging and set the “Staging domain match”. You can enter a full domain for an exact match (e.g., "staging.example.com"), or a suffix starting with a dot to match any host that ends with it (e.g., ".greatbigstorm.com"). If left empty or invalid, it will fall back to ".greatbigstorm.com".

= Will this affect my production site? =

No, the plugin only modifies robots.txt on domains ending with .greatbigstorm.com

== Changelog ==

= 1.1.1 =
* Add: `google-inspectiontool` to blocked crawler list

= 1.1.0 =
* Refactor: Break single-file plugin into modular components in `includes/` directory
* Add: Optional "Block crawlers with robots.txt" checkbox setting (disabled by default)
* Add: Auto-enable "Discourage search engines" setting on plugin activation for staging domains
* Add: Non-dismissible admin warning if search engine discouragement is disabled on staging
* Add: `uninstall.php` to clean up all plugin data from database on uninstall
* Improve: Robots.txt blocking now optional to allow crawlers to receive HTTP 410 responses
* Improve: Updated documentation explaining when to enable/disable robots.txt blocking

= 1.0.5 =
* Add common status monitors and utilities to the allowlist.

= 1.0.4 =
* Add crawler allowlist to explicitly permit known non-search bots (default includes "Plesk screenshot bot"). New filter: `bigstorm_stage_crawler_allowlist`.

= 1.0.3 =
* Exclude `.gitignore` and `package.sh` from GitHub update installs

= 1.0.2 =
* Normalize folder name during update so WordPress installs directly to `bigstorm-stage`

= 1.0.1 =
* Add GitHub-powered updates
* Add settings page for staging domain match
* Add persistent dismissal for admin notice
* Add crawler 410 behavior and details modal

= 1.0.0 =
* Initial release
