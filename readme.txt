=== External Media Importer ===
Contributors: dimitrigogelia
Tags: media, import, external, files, migration
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.0.29
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan WordPress posts for external media files and import them into your media library with full control and detailed logging.

== Description ==

External Media Importer scans your WordPress posts for externally hosted media files and imports them directly into your WordPress media library. After import, it automatically replaces the old external URLs in your post content with the new local URLs, ensuring all your media is served from your own site.

= How It Works =

The plugin uses five complementary URL extraction strategies to find external files in your content:

1. **Regex pattern matching** -- Scans raw post content for URLs matching configured file extensions.
2. **HTML anchor tag parsing** -- Extracts URLs from `<a href>` links pointing to external files.
3. **Media tag parsing** -- Finds external sources in `<img>`, `<video>`, `<audio>`, and `<source>` elements.
4. **Srcset attribute parsing** -- Detects external images referenced in responsive `srcset` attributes on `<img>` and `<source>` tags.
5. **CSS background-image parsing** -- Extracts URLs from `background-image: url(...)` declarations in inline styles and `<style>` blocks.

= Key Features =

* **Batch scanning** -- Processes posts in configurable batches (10-200 per request) to handle sites of any size.
* **Dry run mode** -- Check file sizes via HEAD requests before committing to an import.
* **Duplicate prevention** -- Already-imported files are detected and skipped automatically.
* **Dead link detection** -- URLs that returned errors on previous attempts are tracked and auto-skipped.
* **Resume interrupted scans** -- If a scan is interrupted (browser closed, timeout), resume exactly where you left off.
* **Progress tracking** -- Real-time progress bars for scanning, importing, and dry runs.
* **Import logs** -- Every import attempt is logged with status (success, error, skipped), timestamps, and details.
* **CSV export** -- Download filtered logs as CSV for offline analysis or reporting.
* **Retry failed imports** -- One-click retry of all previously failed imports.
* **Dashboard statistics** -- Overview of total imports, success/error/skipped counts, disk space used, and top errors.
* **Configurable file types** -- Choose which file extensions to scan for using a Select2 tagging interface.
* **Multiple URL filters** -- Restrict scanning to specific external servers by adding one or more base URLs.
* **Post type and status filters** -- Target specific post types and statuses (publish, draft, pending, private, future).
* **Quick Scan** -- Spot-check a single post by entering its ID or URL.
* **Permission levels** -- Restrict plugin access to administrators only or open it to editors and above.
* **Internationalization** -- Fully translatable with English and Georgian (ka_GE) translations included.
* **Clean uninstall** -- Removes all database tables and options when deleted.

= URL Replacement =

After importing a file, the plugin replaces the external URL in your post content. It handles multiple URL encoding variants to ensure no references are missed:

* Standard URLs
* HTML entity-encoded URLs (`&amp;` instead of `&`)
* URL-encoded paths (`%20` for spaces)
* Fully encoded path segments
* Protocol-relative URLs (`//example.com/...`)

== Installation ==

1. Upload the `external-media-importer` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Navigate to **Tools > External Media** to begin scanning your posts for external media files.
4. (Optional) Go to **Tools > External Media > Settings** to configure file types, external server URLs, batch size, and permission levels before scanning.

== Frequently Asked Questions ==

= What file types are supported? =

The plugin supports a configurable list of file extensions. The defaults are: jpg, jpeg, png, gif, pdf, doc, docx, zip, mp4, and mp3. You can add or remove extensions from the Settings page using the Select2 tagging interface.

= Does it replace URLs in post content? =

Yes. After a file is successfully imported into the WordPress media library, the plugin automatically replaces the old external URL with the new local URL in the post content. It handles all common encoding variants including HTML entity-encoded, URL-encoded, fully encoded paths, and protocol-relative URLs.

= What happens if an import fails? =

Failed imports are logged with an "Error" status along with the error message. You can view all errors on the Logs page by filtering to the "Error" tab. A "Retry Failed" button lets you re-attempt all failed imports in one click with progress tracking.

= Can I limit which external servers to scan? =

Yes. On the Settings page, you can add one or more external server base URLs using the "External Server URLs" field. Only URLs that start with one of these base URLs will be included in scan results. This is useful when you want to import files from specific CDNs or old domains only.

= Does it work with custom post types? =

Yes. The scan form displays all public post types registered on your site. You can select one or more post types to scan using the dropdown. Pages, posts, and any custom post type (e.g., products, portfolios) are all supported.

= Is it safe to use on a large site? =

Yes. The plugin is designed for sites of any size. Posts are processed in configurable batches (default 50, adjustable from 10 to 200). An SQL pre-filter at the database level skips posts that cannot contain external file URLs, significantly reducing processing time. If a scan is interrupted, you can resume from exactly where it left off. The dry run mode lets you check file sizes before committing to any imports.

= What languages are supported? =

The plugin ships with English and Georgian (ka_GE) translations. It is fully internationalized and can be translated into any language using the included `.pot` file. Translators can generate `.po` and `.mo` files for their locale using tools like Poedit or Loco Translate.

== Screenshots ==

1. Main scan page with post type selection and status filters.
2. Scan results showing posts with external files and import checkboxes.
3. Import progress with real-time status updates.
4. Dry run results showing file sizes before importing.
5. Import logs page with status filter tabs and pagination.
6. Settings page with file types, URL filters, batch size, and permissions.
7. Dashboard with import statistics and top errors.

== Changelog ==

= 1.0.29 =
* Full internationalization (i18n) support with `__()` and `_e()` for all user-facing strings.
* English and Georgian (ka_GE) translations included.
* Added `.pot` template file for community translations.
* Added README documentation and WordPress.org readme.txt.

= 1.0.28 =
* New URL extraction strategy: scan `srcset` attributes on `<img>` and `<source>` tags for external images.
* New URL extraction strategy: parse CSS `background-image: url(...)` in inline styles and `<style>` blocks.

= 1.0.27 =
* Multiple external URL filters using Select2 tagging mode.
* Comma-separated storage with starts-with matching for each base URL.
* Backward compatible with existing single-URL configuration values.

= 1.0.26 =
* Configurable permission levels on Settings page.
* Choose between "Administrators Only" (`manage_options`) or "Editors and above" (`edit_posts`).
* Permission check applied to all menu pages, AJAX handlers, CSV export, and dashboard.

= 1.0.25 =
* Import statistics dashboard as a new submenu page.
* Overview cards showing total, success, error, and skipped import counts with percentages.
* Storage section with disk space used by imported files and unique posts affected.
* Top 5 error messages table for quick issue identification.
* Disk space cached in a 1-hour transient for performance.
* Responsive CSS grid layout.

= 1.0.24 =
* Added progress bar to dry run mode with sequential one-at-a-time URL processing.
* Client-side `formatBytes()` helper for human-readable file sizes.
* Summary displayed after dry run completion.

= 1.0.23 =
* Dry run mode: check file sizes via HEAD requests before importing.
* File size badges displayed inline next to filenames.
* Total summary with file count and combined size.

= 1.0.22 =
* Per-action nonce verification for enhanced security.
* Replaced shared nonce with 8 individual nonces for each AJAX action.
* Removed unused nonce field from scan form.

= 1.0.21 =
* Media tag parsing for `<img src>`, `<video src>`, `<audio src>`, and `<source src>` elements.
* Merged extraction strategies into shared loop with deduplication via `$seen_urls`.

= 1.0.20 =
* Configurable batch size setting on Settings page (range: 10-200, default: 50).
* Saved as `emi_batch_size` option and passed to JavaScript via `wp_localize_script`.

= 1.0.19 =
* Retry failed imports with one-click "Retry Failed" button on logs page.
* Fetches all error log entries and re-attempts import with progress tracking.
* Deletes old error entry before re-import to keep logs clean.
* Auto-reloads page on completion.

= 1.0.18 =
* Export filtered logs to CSV download.
* Respects current status filter (All, Success, Error, Skipped).
* UTF-8 encoded CSV with all log columns.

= 1.0.17 =
* Quick Scan: scan a single post by entering its ID or URL.
* URL resolution via `url_to_postid()` for pasting permalinks.
* Results displayed in standard table format with import controls.

= 1.0.16 =
* Post status filter with checkboxes for publish, draft, pending, private, and future statuses.
* SQL query uses `IN (...)` clause for multiple status filtering.
* Client-side and server-side validation.

= 1.0.15 =
* Dead link detection: tracks URLs that previously failed with `skipped` log status.
* Posts where all files are dead or already imported are excluded from scan results.
* Dead-link files shown with red badge and strikethrough (unchecked by default).
* "Clear dead link history" option to reset and re-scan.

= 1.0.14 =
* Resume interrupted scans using localStorage persistence.
* Saves post IDs, post type, batch index, and accumulated results.
* Resume banner displayed on page load when saved state exists.
* Dismiss option to clear saved state and start fresh.

= 1.0.13 =
* Moved "Clear All Logs" button to Settings page under "Data Management" section.
* Shows log count and disables button when no logs exist.
* JavaScript confirmation dialog before clearing.

= 1.0.12 =
* Added "Clear All Logs" button on logs page.

= 1.0.11 =
* SQL pre-filter for scanning: `LIKE` query filters posts at the database level by `http` and file extensions.
* Skips posts that cannot contain external file URLs, significantly improving scan performance.

= 1.0.10 =
* Logs pagination with 50 entries per page using WordPress `paginate_links()`.
* Status filter tabs (All, Success, Error, Skipped) with entry counts.

= 1.0.9 =
* Duplicate import prevention: already-imported files shown with badge and strikethrough.
* Server-side check in import handler skips duplicate files.
* Previously imported files unchecked by default in scan results.

= 1.0.8 =
* Robust URL replacement after import.
* Handles HTML entity-encoded (`&amp;`), URL-encoded (`%20`), fully-encoded paths, and protocol-relative (`//`) URL variants.

= 1.0.7 =
* Clean uninstall hook: drops `wp_external_media_log` database table.
* Deletes `emi_file_types`, `emi_external_url`, and related options on uninstall.

= 1.0.6 =
* Fixed `upload_dir` filter cleanup: stored closure in variable for proper `remove_filter()` usage.

= 1.0.5 =
* Select2 multi-select for file type configuration using tagging mode.
* Removed duplicate settings save handler.

= 1.0.4 =
* Added `<a href>` tag parsing alongside regex URL detection.
* Extracted shared `extract_external_urls()` method to eliminate code duplication.

= 1.0.3 =
* Batch scanning: processes 50 posts per AJAX request instead of one at a time.
* Fixed progress bar visibility.

= 1.0.2 =
* Updated plugin header to WordPress standards.
* Added author: Dimitri Gogelia.

= 1.0.1 =
* Fixed regex extension ordering using `usort()` by length descending (e.g., docx before doc).

= 1.0.0 =
* Initial release.
* OOP class-based architecture.
* Regex-based external URL detection.
* Import external files into WordPress media library.
* Automatic URL replacement in post content.
* Import logging with custom database table.
* Admin interface under Tools menu.

== Upgrade Notice ==

= 1.0.29 =
Adds full internationalization support with English and Georgian translations. No database changes required.

= 1.0.28 =
Adds srcset and CSS background-image scanning for more comprehensive external file detection.

= 1.0.22 =
Security improvement: per-action nonce verification replaces shared nonce. Recommended update.

= 1.0.11 =
Significant performance improvement for scanning large sites via SQL pre-filtering.

= 1.0.7 =
Adds clean uninstall support. Plugin now removes all data when deleted through WordPress.
