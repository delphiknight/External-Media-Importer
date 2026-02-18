**[English](README.md)** | [ქართული (Georgian)](README-ka.md)

# External Media Importer

A WordPress plugin that scans your posts, pages, and custom post types for external media file URLs and imports them into your WordPress media library. All external URLs in post content are automatically replaced with local URLs after import.

Built for sites that host media on external servers and need to migrate files into WordPress, or for cleaning up content that references third-party file hosts.

## Features

- **Batch scanning** -- Scans posts in configurable batches (10--200 per request) to find external media URLs without overloading the server
- **5 URL extraction strategies** -- Regex pattern matching, HTML tag parsing (`<img>`, `<video>`, `<audio>`, `<source>`, `<a>`), `srcset` attribute parsing, and CSS `background-image: url(...)` extraction
- **One-click import** -- Downloads external files, creates WordPress media attachments, and replaces all URL variants in post content
- **Smart URL replacement** -- Handles HTML entity-encoded (`&amp;`), URL-encoded (`%20`), fully-encoded, and protocol-relative (`//`) URL variants
- **Post type and status filters** -- Scan any public post type and filter by publish, draft, pending, private, or future status
- **Quick Scan** -- Scan a single post by entering its ID or URL
- **Duplicate prevention** -- Already-imported files are detected and shown with a badge, unchecked by default
- **Dead link detection** -- Files returning HTTP errors are marked as dead links and auto-skipped in future scans
- **Resume interrupted scans** -- Scan progress is saved to localStorage; a resume banner appears if a scan was interrupted
- **Dry run mode** -- Send HEAD requests to check file sizes before committing to an import
- **Real-time progress** -- Progress bar with file-by-file status updates during scanning and importing
- **Import logs** -- Paginated log page with status filter tabs (All / Success / Error / Skipped)
- **CSV export** -- Download filtered logs as a CSV file
- **Retry failed imports** -- One-click retry of all failed import entries
- **Dashboard** -- Stats overview showing total, success, error, and skipped counts with percentages, disk space used, and top error messages
- **Configurable file types** -- Select2 tagging interface for choosing allowed file extensions
- **Multiple URL filters** -- Select2 tagging interface for specifying external base URLs to limit scanning scope
- **Configurable batch size** -- Adjust the number of posts per AJAX request (10--200)
- **Permission levels** -- Restrict access to administrators only or open to editors and above
- **Localization ready** -- Full i18n support with English and Georgian translations included
- **Clean uninstall** -- Drops the log database table and deletes all plugin options on uninstall

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher

## Installation

1. Download the plugin and upload the `external-media-importer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Media Importer** in the WordPress admin sidebar to begin scanning.

Alternatively, upload the plugin ZIP file via **Plugins > Add New > Upload Plugin** in the WordPress admin.

## Usage

### Scanning Posts

1. Go to **Media Importer** in the admin menu.
2. Select a **post type** from the dropdown (posts, pages, or any registered custom post type).
3. Check the **post statuses** you want to include (publish, draft, pending, private, future).
4. Click **Scan Posts**.
5. The plugin scans posts in batches, showing a progress bar. When finished, a table of posts with external media files is displayed.

Each result row shows the post title, the number of external files found, and checkboxes for each file. Files that have already been imported or are dead links are indicated with badges and are unchecked by default.

The plugin uses an SQL pre-filter to skip posts that cannot contain external file URLs, significantly improving scan speed on large sites.

### Importing Files

1. After scanning, review the list of found external files.
2. Check or uncheck individual files as needed.
3. Click **Import Selected Files**.
4. A progress bar tracks the import. Each file is downloaded, added to the WordPress media library as an attachment, and the external URL in the post content is replaced with the new local URL.

The URL replacement logic handles multiple encoding variants of the same URL, ensuring no broken references remain in the content.

### Quick Scan

1. On the main **Media Importer** page, find the **Quick Scan** section.
2. Enter a post ID or a full post URL.
3. Click **Quick Scan**.
4. The plugin resolves the post and displays any external media files found, with the same import controls as a full scan.

This is useful for spot-checking individual posts without running a full scan.

### Dry Run

1. After scanning, select the files you want to check.
2. Click **Dry Run (Check Sizes)**.
3. The plugin sends HTTP HEAD requests to each file URL and displays the file size next to each filename.
4. A summary shows the total number of files and combined size.

Use this to estimate disk space requirements before importing.

### Resume Scan

If a scan is interrupted (browser closed, network error, page navigation), the plugin saves progress to the browser's localStorage. When you return to the Media Importer page:

1. A **resume banner** appears showing the interrupted scan details.
2. Click **Resume** to continue from where the scan left off.
3. Click **Dismiss** to clear the saved state and start fresh.

## Settings

Navigate to **Media Importer > Settings** to configure the plugin.

### File Types

Use the Select2 tagging field to specify which file extensions to scan for. Type an extension and press Enter to add it. Common types include `jpg`, `png`, `gif`, `pdf`, `docx`, `mp4`, and more.

### External URL Filters

Use the Select2 tagging field to add one or more base URLs. When set, only files hosted on URLs starting with these base URLs will be included in scan results. This is useful for targeting a specific external server or CDN. Leave empty to scan for all external URLs.

### Batch Size

Set the number of posts processed per AJAX request during scanning. The default is 50. Lower values (10--20) reduce server load; higher values (100--200) speed up scanning on capable servers. Range: 10--200.

### Permission Level

Choose who can access the plugin:

- **Administrators Only** -- Only users with the `manage_options` capability (default)
- **Editors and Above** -- Users with the `edit_posts` capability

### Data Management

- **Clear All Logs** -- Deletes all entries from the import log table. A confirmation dialog is shown before clearing. The button is disabled when the log is empty.

## Dashboard and Logs

### Dashboard

Navigate to **Media Importer > Dashboard** to view import statistics:

- **Overview** -- Total imports, success count, error count, and skipped count with percentages
- **Storage** -- Total disk space used by imported files and the number of unique posts affected
- **Top Errors** -- The 5 most frequent error messages to help diagnose import issues

Disk space data is cached for one hour using a WordPress transient.

### Logs

Navigate to **Media Importer > Logs** to view the import log:

- Logs are paginated at 50 entries per page.
- Use the **status filter tabs** (All / Success / Error / Skipped) to filter entries.
- Each log entry shows the post, file URL, status, and timestamp.

### CSV Export

On the Logs page, click **Export to CSV** to download the current filtered log entries as a UTF-8 CSV file. The export respects the active status filter.

### Retry Failed Imports

On the Logs page, click **Retry Failed** to re-attempt all imports that previously failed. The old error log entries are deleted before each retry. A progress bar tracks the retry process, and the page reloads when complete.

## Translation

The plugin is fully internationalized. All user-facing strings in PHP use `__()` and `_e()`, and JavaScript strings are passed via `wp_localize_script()`.

### Included Languages

- English (default)
- Georgian (`ka_GE`)

### Adding a New Translation

1. Use a tool like [Poedit](https://poedit.net/) or [Loco Translate](https://wordpress.org/plugins/loco-translate/) to open the `.pot` file located in the `languages/` directory.
2. Translate the strings into your language.
3. Save the `.po` and `.mo` files with the correct locale code (e.g., `external-media-importer-fr_FR.po` and `external-media-importer-fr_FR.mo`).
4. Place the files in the `languages/` directory inside the plugin folder.

## Screenshots

1. **Main scan page** -- Post type selector, status filters, and scan button
2. **Scan results** -- Table of posts with external files, checkboxes, and import controls
3. **Import progress** -- Real-time progress bar during file import
4. **Quick Scan** -- Single post scan by ID or URL
5. **Dry run results** -- File size badges displayed next to each file
6. **Settings page** -- File types, URL filters, batch size, and permission configuration
7. **Dashboard** -- Stats overview with counts, percentages, and disk space
8. **Logs page** -- Paginated log with status filter tabs and export button

## Changelog

### 1.0.29
- Full localization: wrapped all PHP strings in translation functions, passed JS strings via `emiAjax.i18n`, added `load_plugin_textdomain()` support, created `.pot` template and Georgian (`ka_GE`) translation files
- Added English README.md and Georgian README-ka.md documentation

### 1.0.28
- Added Strategy 4: `srcset` attribute parsing on `<img>` and `<source>` tags for responsive image URLs
- Added Strategy 5: CSS `background-image: url(...)` extraction from inline styles and `<style>` blocks

### 1.0.27
- Replaced single external URL filter with Select2 tagging mode for multiple base URLs
- Each URL uses starts-with matching; backward compatible with existing single-URL values

### 1.0.26
- Added configurable permission level on Settings page (Administrators Only or Editors and Above)
- Applied capability check across all menu pages, AJAX handlers, CSV export, and dashboard

### 1.0.25
- Added Dashboard submenu page with import statistics
- Overview section with total/success/error/skipped counts and percentages
- Storage section with disk space used and unique posts affected
- Top 5 error messages table; responsive CSS grid layout

### 1.0.24
- Added progress bar to dry run mode with sequential file processing
- File size formatting with `formatBytes()` helper; summary shown after completion

### 1.0.23
- Added dry run mode: HEAD requests to check file sizes before importing
- File size badges displayed inline next to filenames; total summary shown

### 1.0.22
- Replaced shared nonce with per-action nonce verification for all 9 AJAX handlers
- Improved security by validating each action independently

### 1.0.21
- Added media tag parsing for `<img src>`, `<video src>`, `<audio src>`, and `<source src>` tags
- Merged extraction strategies with deduplication via `$seen_urls`

### 1.0.20
- Added configurable batch size setting (10--200 posts per AJAX request, default 50)
- Passed to JavaScript via `wp_localize_script`

### 1.0.19
- Added retry failed imports button on logs page
- Deletes old error entry before each re-import attempt; progress bar and auto-reload

### 1.0.18
- Added CSV export for import logs
- Respects current status filter; UTF-8 encoded with all log columns

### 1.0.17
- Added Quick Scan: scan a single post by ID or URL
- URL resolution via `url_to_postid()`; results in same table format

### 1.0.16
- Added post status filter with checkboxes for publish, draft, pending, private, and future
- Server-side validation of selected statuses

### 1.0.15
- Added dead link detection and skipping
- Posts where all files are dead or already imported are excluded from results
- Dead-link files shown with red badge and strikethrough

### 1.0.14
- Added resume interrupted scans via localStorage persistence
- Resume banner on page load with dismiss option

### 1.0.13
- Moved Clear All Logs button to Settings page under Data Management section
- Shows log count; disabled when empty; JavaScript confirmation dialog

### 1.0.12
- Added Clear Logs button on logs page

### 1.0.11
- Added SQL pre-filter for scanning: `LIKE` query filters posts at database level
- Skips posts that cannot contain external file URLs

### 1.0.10
- Added logs pagination (50 per page) with WordPress `paginate_links()`
- Added status filter tabs (All / Success / Error / Skipped) with counts

### 1.0.9
- Added duplicate import prevention
- Already-imported files shown with badge and strikethrough, unchecked by default
- Server-side check in import handler skips duplicates

### 1.0.8
- Robust URL replacement after import
- Handles HTML entity-encoded, URL-encoded, fully-encoded, and protocol-relative URL variants

### 1.0.7
- Added uninstall hook: drops log table and deletes all plugin options

### 1.0.6
- Fixed `upload_dir` filter cleanup using stored closure reference and `remove_filter()`

### 1.0.5
- Added Select2 multi-select for file types on settings page with tagging mode
- Removed duplicate settings save handler

### 1.0.4
- Added `<a href>` tag parsing alongside regex URL detection
- Extracted shared `extract_external_urls()` method to eliminate code duplication

### 1.0.3
- Fixed progress bar visibility
- Added batch scanning (50 posts per AJAX request)

### 1.0.2
- Updated plugin header to WordPress standards
- Added author Dimitri Gogelia

### 1.0.1
- Fixed regex extension ordering: longer extensions matched before shorter ones (e.g., `docx` before `doc`)

### 1.0.0
- Initial release with OOP class-based architecture
- External media scanning with regex URL detection
- Import to media library with URL replacement in post content
- Admin page with post type selection
- Import logging with database table

## License

This plugin is licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

**Dimitri Gogelia**
[https://gogelia.ge](https://gogelia.ge)
