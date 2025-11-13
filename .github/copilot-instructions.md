# Big Storm Staging Plugin - AI Coding Guide

## Architecture Overview

This WordPress plugin prevents search engine indexing on staging domains by:
1. Modifying `robots.txt` to add `Disallow: /` 
2. Returning HTTP 410 (Gone) to search crawlers on staging domains

**Key architectural decision**: The plugin is now **modular** with separate classes in `includes/` directory. All functionality is coordinated by a lightweight main class (`Big_Storm_Staging`) in `bigstorm-stage.php` that initializes components in dependency order.

**No external dependencies** - Self-contained with **self-managed GitHub updates**.

## File Structure

```
bigstorm-stage/
├── bigstorm-stage.php              # Main plugin file (110 lines) - coordinates components
├── includes/
│   ├── class-admin-settings.php    # Settings page and domain configuration
│   ├── class-staging-protection.php # Robots.txt modification and crawler blocking
│   ├── class-github-updater.php    # GitHub API integration and update system
│   ├── class-plugin-modal.php      # Plugin details modal and readme parsing
│   └── class-admin-notices.php     # Dismissible admin notices
├── readme.txt                       # WordPress.org-style readme
├── index.php                        # Empty security file
└── package.sh                       # Build script for distribution
```

## Component Architecture

**Initialization order matters** - Components are initialized in dependency order in `bigstorm-stage.php`:

1. **Admin Settings** (`class-admin-settings.php`) - Base component, no dependencies
   - Manages `bigstorm_stage_domain_suffix` option
   - Provides `get_staging_suffix()` and `normalize_suffix()` methods
   - Renders settings page and adds Settings link to plugin row

2. **Staging Protection** (`class-staging-protection.php`) - Depends on Admin Settings
   - Core domain logic: `is_staging_domain()` checks current host against configured suffix
   - Modifies `robots.txt` via `robots_txt` filter (priority 10)
   - Sends HTTP 410 via `template_redirect` hook (priority 0 - earliest)
   - Manages crawler detection with filterable allowlist and crawler list

3. **GitHub Updater** (`class-github-updater.php`) - Independent component
   - Hooks `pre_set_site_transient_update_plugins` and `site_transient_update_plugins`
   - Caches remote metadata in transient (`bigstorm_stage_update_meta`, 6 hours)
   - Handles folder renaming via `upgrader_source_selection` filter
   - Public methods: `get_remote_release()`, `fetch_github_file_at_ref()`, `get_release_notes_html()`

4. **Plugin Modal** (`class-plugin-modal.php`) - Depends on GitHub Updater
   - Intercepts `plugins_api` filter for slug `bigstorm-stage`
   - Uses GitHub Updater to fetch tag-specific READMEs
   - Adds "View details" link via `plugin_row_meta` filter
   - Enqueues Thickbox on plugins.php

5. **Admin Notices** (`class-admin-notices.php`) - Depends on Staging Protection
   - Shows warning on non-staging domains (uses `is_staging_domain()`)
   - Dismissible via AJAX (`bigstorm_stage_dismiss`) or nonce-protected URL
   - User meta key: `bigstorm_stage_dismiss_remove_notice`

## Core Domain Logic

**Staging Detection** (in `Big_Storm_Staging_Protection`):
- Configurable via Settings → Big Storm Staging (option: `bigstorm_stage_domain_suffix`)
- Default: `.greatbigstorm.com` (suffix match)
- Supports exact domain match (e.g., `staging.example.com`) or suffix (`.greatbigstorm.com`)
- Domain normalization in `Big_Storm_Admin_Settings::normalize_suffix()`: strips protocol, port, path, lowercases

**Crawler Detection** (in `Big_Storm_Staging_Protection::is_search_crawler()`):
- **Allowlist checked first** (`bigstorm_stage_crawler_allowlist` filter) - excludes non-search bots (e.g., Plesk, StatusCake)
- Then checks against known crawler list (`bigstorm_stage_crawlers` filter)
- Fallback regex for common crawler terms if no matches

## GitHub Update System

**Update flow** (in `Big_Storm_GitHub_Updater`):
1. `check_for_update()` hooks `pre_set_site_transient_update_plugins`
2. Queries GitHub API (`/repos/greatbigstorm/bigstorm-stage-plugin/releases/latest`)
3. Caches remote metadata (6 hours)
4. Injects update into `$transient->response` if newer version found

**Folder name normalization** (`maybe_rename_github_source()`):
- GitHub zips extract as `{repo}-{sha}` but WordPress expects `bigstorm-stage/`
- Renames source folder during upgrade via `upgrader_source_selection` filter
- Removes `.gitignore` and `package.sh` from update payload

**Plugin details modal** (in `Big_Storm_Plugin_Modal`):
- Fetches tag-specific `readme.txt` from GitHub API (base64-encoded content)
- Parses WordPress-style sections (Description, Installation, FAQ, Changelog)
- Falls back to release notes if README unavailable

## WordPress Conventions

**Tabs for indentation**: This codebase uses tabs (WordPress standard), not spaces.

**Hook priorities**: 
- `template_redirect` at priority 0 for crawler 410s (runs before template loading)
- `robots_txt` at default priority 10

**Class naming**: WordPress convention - `Big_Storm_` prefix, file names match class names with `class-` prefix

**Text domain**: `bigstorm-stage` for all translatable strings

## Development Workflow

**Adding new functionality**:
1. Determine which component owns the logic (or create a new class in `includes/`)
2. Follow existing dependency patterns (e.g., notices depend on staging protection)
3. Update `Big_Storm_Staging::init()` to initialize new components in correct order
4. Consider which hooks/filters are needed and at what priority

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

## Common Pitfalls

1. **Component initialization order**: Admin Settings must init before Staging Protection (provides domain logic)
2. **GitHub API rate limits**: Update checks cache for 6 hours to avoid hitting limits
3. **Folder naming**: Updates fail if extracted folder doesn't match `bigstorm-stage/` - handled by `maybe_rename_github_source()`
4. **Crawler allowlist order**: Allowlist is checked *before* crawler list - allows overriding false positives
5. **Settings sanitization**: `normalize_suffix()` is strict - returns empty string on invalid domains (falls back to default)
6. **Cross-component dependencies**: Admin Notices needs Staging Protection, Plugin Modal needs GitHub Updater - follow existing patterns

