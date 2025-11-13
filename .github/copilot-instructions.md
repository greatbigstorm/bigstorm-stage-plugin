# Big Storm Staging Plugin - AI Coding Guide

## Architecture Overview

This is a single-file WordPress plugin (`bigstorm-stage.php`) with **self-managed GitHub updates**. The plugin prevents search engine indexing on staging domains by:
1. Modifying `robots.txt` to add `Disallow: /` 
2. Returning HTTP 410 (Gone) to search crawlers on staging domains

**Key architectural decision**: All functionality lives in one class (`Big_Storm_Staging`) initialized at file bottom. No external dependencies.

## Core Domain Logic

**Staging Detection** (`is_staging_domain()`):
- Configurable via Settings → Big Storm Staging (option: `bigstorm_stage_domain_suffix`)
- Default: `.greatbigstorm.com` (suffix match)
- Supports exact domain match (e.g., `staging.example.com`) or suffix (`.greatbigstorm.com`)
- Domain normalization via `normalize_suffix()`: strips protocol, port, path, lowercases

**Crawler Detection** (`is_search_crawler()`):
- Uses filterable allowlist (`bigstorm_stage_crawler_allowlist`) to exclude non-search bots (e.g., Plesk, StatusCake)
- Checks against known crawler list (`bigstorm_stage_crawlers` filter) before fallback regex
- Returns HTTP 410 via `template_redirect` hook at priority 0 (earliest possible)

## GitHub Update System

**Critical files**: Self-contained update mechanism without WordPress.org dependency.

**Update flow**:
1. `check_for_update()` hooks `pre_set_site_transient_update_plugins`
2. Queries GitHub API (`/repos/greatbigstorm/bigstorm-stage-plugin/releases/latest`)
3. Caches remote metadata in transient (`bigstorm_stage_update_meta`, 6 hours)
4. Injects update into `$transient->response` if newer version found

**Plugin details modal** (`plugins_api_info()`):
- Intercepts `plugins_api` filter for slug `bigstorm-stage`
- Fetches tag-specific `readme.txt` from GitHub API (base64-encoded content)
- Parses WordPress-style sections (Description, Installation, FAQ, Changelog)
- Falls back to release notes via `/repos/.../releases/tags/{tag}` if README unavailable

**Folder name normalization** (`maybe_rename_github_source()`):
- GitHub zips extract as `{repo}-{sha}` but WordPress expects `bigstorm-stage/`
- Renames source folder during upgrade via `upgrader_source_selection` filter
- Removes `.gitignore` and `package.sh` from update payload

## WordPress Conventions

**Tabs for indentation**: This codebase uses tabs (WordPress standard), not spaces.

**Hook priorities**: 
- `template_redirect` at priority 0 for crawler 410s (runs before template loading)
- `robots_txt` at default priority 10

**Admin notices**: 
- `maybe_show_remove_notice()` warns when active on non-staging domains
- Dismissible via AJAX (`bigstorm_stage_dismiss`) or query param with nonce
- User meta key: `bigstorm_stage_dismiss_remove_notice`

**Text domain**: `bigstorm-stage` for all translatable strings

## Development Workflow

**Packaging**: Run `./package.sh` to create `dist/bigstorm-stage-{version}.zip`
- Extracts version from plugin header (`* Version:`)
- Excludes `.git/`, `.github/`, `.gitignore`, `package.sh`, build artifacts
- Output structure: zip expands to `bigstorm-stage/` folder (WordPress expects this)

**Version bumps**: Update version in plugin header (line 14) - used by both packaging and GitHub update checks

**Testing staging logic**: 
- Modify option `bigstorm_stage_domain_suffix` via Settings or directly
- Test crawler detection with User-Agent spoofing
- Verify `robots.txt` output at `/robots.txt`

## Key Filters for Customization

```php
// Modify crawler detection list
add_filter('bigstorm_stage_crawlers', function($bots) {
    $bots[] = 'custombot';
    return $bots;
});

// Allowlist non-search bots (e.g., monitoring services)
add_filter('bigstorm_stage_crawler_allowlist', function($allowed) {
    $allowed[] = 'my-monitoring-bot';
    return $allowed;
});
```

## File Structure

- `bigstorm-stage.php` - Single plugin file (1050 lines)
- `readme.txt` - WordPress.org-style readme (used for plugin modal)
- `index.php` - Empty security file ("Silence is golden")
- `package.sh` - Build script for creating distribution zip
- `.github/` - Not included in distribution (see `package.sh` excludes)

## Common Pitfalls

1. **GitHub API rate limits**: Update checks cache for 6 hours to avoid hitting limits
2. **Folder naming**: Updates fail if extracted folder doesn't match `bigstorm-stage/` - handled by `maybe_rename_github_source()`
3. **Crawler allowlist order**: Allowlist is checked *before* crawler list - allows overriding false positives
4. **Settings sanitization**: `normalize_suffix()` is strict - returns empty string on invalid domains (falls back to default)
