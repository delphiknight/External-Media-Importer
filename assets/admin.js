jQuery(document).ready(function($) {
    let postsWithFiles = [];
    let importQueue = [];
    let currentIndex = 0;
    let skippedDeadLinkPosts = 0;

    // Shorthand for i18n strings
    var i18n = emiAjax.i18n || {};

    // Number of posts to scan per AJAX request (configurable via Settings)
    const SCAN_BATCH_SIZE = parseInt(emiAjax.batchSize, 10) || 50;

    // --- localStorage keys for scan persistence ---
    const LS_POST_IDS    = 'emi_scan_postIds';
    const LS_POST_TYPE   = 'emi_scan_postType';
    const LS_NEXT_BATCH  = 'emi_scan_nextBatch';
    const LS_RESULTS     = 'emi_scan_results';

    // Simple sprintf-like helper for i18n strings
    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return str.replace(/%(\d+\$)?[ds]/g, function(match) {
            var argMatch = match.match(/^%(\d+)\$/);
            if (argMatch) {
                return args[parseInt(argMatch[1], 10) - 1];
            }
            return args[i++];
        });
    }

    function saveScanProgress(postIds, postType, batchIndex, results) {
        try {
            localStorage.setItem(LS_POST_IDS, JSON.stringify(postIds));
            localStorage.setItem(LS_POST_TYPE, postType);
            localStorage.setItem(LS_NEXT_BATCH, batchIndex.toString());
            localStorage.setItem(LS_RESULTS, JSON.stringify(results));
        } catch (e) {
            // localStorage full or unavailable — scan still works, just can't resume
        }
    }

    function clearScanProgress() {
        localStorage.removeItem(LS_POST_IDS);
        localStorage.removeItem(LS_POST_TYPE);
        localStorage.removeItem(LS_NEXT_BATCH);
        localStorage.removeItem(LS_RESULTS);
    }

    function getSavedScan() {
        try {
            var postIds = localStorage.getItem(LS_POST_IDS);
            var postType = localStorage.getItem(LS_POST_TYPE);
            var nextBatch = localStorage.getItem(LS_NEXT_BATCH);
            var results = localStorage.getItem(LS_RESULTS);

            if (postIds && postType && nextBatch !== null) {
                postIds = JSON.parse(postIds);
                nextBatch = parseInt(nextBatch, 10);
                results = results ? JSON.parse(results) : [];

                var processed = nextBatch * SCAN_BATCH_SIZE;
                // Only offer resume if there's still work to do
                if (processed < postIds.length) {
                    return {
                        postIds: postIds,
                        postType: postType,
                        nextBatch: nextBatch,
                        results: results,
                        processed: processed,
                        total: postIds.length
                    };
                }
            }
        } catch (e) {
            // Corrupted data — ignore
        }
        return null;
    }

    // --- Check for resumable scan on page load ---
    var savedScan = getSavedScan();
    if (savedScan) {
        var pct = Math.round((savedScan.processed / savedScan.total) * 100);
        $('#emi-resume-info').html(
            sprintf(i18n.resumeInfo, savedScan.processed, savedScan.total, pct, savedScan.results.length)
        );
        $('#emi-resume-section').show();
    }

    // Resume button click
    $('#emi-resume-btn').on('click', function() {
        var saved = getSavedScan();
        if (!saved) {
            alert(i18n.noSavedScan);
            $('#emi-resume-section').hide();
            return;
        }

        // Set post type dropdown to match saved scan
        $('#post_type').val(saved.postType);

        // Restore accumulated results
        postsWithFiles = saved.results;
        skippedDeadLinkPosts = 0;

        // Disable controls and show progress
        $('#scan-posts-btn').prop('disabled', true).text(i18n.scanning);
        $('#emi-resume-section').hide();
        $('#posts-section').slideDown();
        $('#scan-progress').show();

        var pct = Math.round((saved.processed / saved.total) * 100);
        $('#scan-progress-bar').css('width', pct + '%').text(pct + '%');
        $('#scan-progress-text').text(sprintf(i18n.postsScannedResumed, saved.processed, saved.total));

        // Continue scanning from where we left off
        scanBatches(saved.postIds, saved.nextBatch);
    });

    // Dismiss resume
    $('#emi-resume-dismiss').on('click', function() {
        clearScanProgress();
        $('#emi-resume-section').hide();
    });

    // Quick Scan by Post ID or URL
    $('#emi-quick-scan-form').on('submit', function(e) {
        e.preventDefault();

        var input = $.trim($('#emi-quick-scan-input').val());
        if (!input) {
            alert(i18n.enterPostIdOrUrl);
            return;
        }

        // Determine if input is a numeric post ID or a URL
        var ajaxData = {
            action: 'emi_scan_single_post',
            nonce: emiAjax.nonces.scan_single
        };

        if (/^\d+$/.test(input)) {
            ajaxData.post_id = input;
        } else {
            ajaxData.post_url = input;
        }

        $('#quick-scan-btn').prop('disabled', true).text(i18n.scanning);

        // Reset results area
        postsWithFiles = [];
        skippedDeadLinkPosts = 0;
        $('#posts-list').empty();
        $('#import-controls').hide();

        $.ajax({
            url: emiAjax.ajaxurl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    var data = response.data;

                    if (data.has_files && data.files.length > 0) {
                        postsWithFiles = [{
                            post_id: data.post_id,
                            post_title: data.post_title,
                            post_url: data.post_url,
                            files: data.files
                        }];

                        $('#posts-section').slideDown();
                        displayPostsTable();
                        $('#import-controls').show();
                    } else {
                        $('#posts-section').slideDown();
                        $('#posts-list').html(
                            '<p style="padding: 20px; text-align: center; color: #666;">' +
                            i18n.noExternalFilesInPost + ' <strong>' + escapeHtml(data.post_title) + '</strong> (ID: ' + data.post_id + ')</p>'
                        );
                    }
                } else {
                    alert(i18n.error + ' ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                alert(i18n.ajaxErrorGeneric + ' ' + error);
            },
            complete: function() {
                $('#quick-scan-btn').prop('disabled', false).text(i18n.quickScan);
            }
        });
    });

    // Scan all posts for external files
    $('#emi-scan-form').on('submit', function(e) {
        e.preventDefault();

        const postType = $('#post_type').val();

        // Collect selected post statuses
        var postStatuses = [];
        $('.emi-post-status:checked').each(function() {
            postStatuses.push($(this).val());
        });
        if (postStatuses.length === 0) {
            alert(i18n.selectOneStatus);
            return;
        }

        // Clear any previous saved scan
        clearScanProgress();
        postsWithFiles = [];
        skippedDeadLinkPosts = 0;

        $('#scan-posts-btn').prop('disabled', true).text(i18n.scanning);
        $('#posts-section').hide();
        $('#posts-list').empty();
        $('#import-controls').hide();
        $('#emi-resume-section').hide();

        // First, get all post IDs
        $.ajax({
            url: emiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'emi_scan_all_posts',
                nonce: emiAjax.nonces.scan_all,
                post_type: postType,
                post_statuses: postStatuses
            },
            success: function(response) {
                if (response.success) {
                    const postIds = response.data.post_ids;

                    if (postIds.length === 0) {
                        alert(i18n.noPostsFound);
                        $('#scan-posts-btn').prop('disabled', false).text(i18n.scanPostsBtn);
                        return;
                    }

                    // Show posts section and progress
                    $('#posts-section').slideDown();
                    $('#scan-progress').show();
                    $('#scan-progress-bar').css('width', '0%').text('0%');
                    $('#scan-progress-text').text(sprintf(i18n.postsScanned, 0, postIds.length));

                    // Save initial state for resume
                    saveScanProgress(postIds, postType, 0, []);

                    // Scan posts in batches
                    scanBatches(postIds, 0);
                } else {
                    alert(i18n.error + ' ' + response.data);
                    $('#scan-posts-btn').prop('disabled', false).text(i18n.scanPostsBtn);
                }
            },
            error: function(xhr, status, error) {
                alert(i18n.ajaxErrorGeneric + ' ' + error);
                $('#scan-posts-btn').prop('disabled', false).text(i18n.scanPostsBtn);
            }
        });
    });

    // Scan posts in batches
    function scanBatches(postIds, batchIndex) {
        const start = batchIndex * SCAN_BATCH_SIZE;

        if (start >= postIds.length) {
            // All batches complete — clear saved progress
            clearScanProgress();
            completeScan();
            return;
        }

        const batch = postIds.slice(start, start + SCAN_BATCH_SIZE);
        const postType = $('#post_type').val();

        $.ajax({
            url: emiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'emi_scan_batch_posts',
                nonce: emiAjax.nonces.scan_batch,
                post_ids: batch
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.posts) {
                        response.data.posts.forEach(function(post) {
                            postsWithFiles.push(post);
                        });
                    }
                    if (response.data.skipped_dead_links) {
                        skippedDeadLinkPosts += response.data.skipped_dead_links;
                    }
                }
            },
            complete: function() {
                // Update progress
                const processed = Math.min(start + SCAN_BATCH_SIZE, postIds.length);
                const total = postIds.length;
                const percentage = Math.round((processed / total) * 100);

                $('#scan-progress-bar').css('width', percentage + '%').text(percentage + '%');
                $('#scan-progress-text').text(sprintf(i18n.postsScanned, processed, total));

                // Save progress for resume (next batch index)
                saveScanProgress(postIds, postType, batchIndex + 1, postsWithFiles);

                // Scan next batch
                scanBatches(postIds, batchIndex + 1);
            }
        });
    }

    // Complete scan and display results
    function completeScan() {
        $('#scan-progress').hide();
        $('#scan-posts-btn').prop('disabled', false).text(i18n.scanPostsBtn);

        if (postsWithFiles.length === 0 && skippedDeadLinkPosts === 0) {
            $('#posts-list').html('<p style="padding: 20px; text-align: center; color: #666;">' + i18n.noExternalFiles + '</p>');
            return;
        }

        if (postsWithFiles.length === 0 && skippedDeadLinkPosts > 0) {
            $('#posts-section').slideDown();
            $('#posts-list').html(
                '<div class="notice notice-warning inline" style="margin: 0 0 15px;">' +
                '<p>' + sprintf(i18n.postsSkippedDead, skippedDeadLinkPosts) + ' ' +
                '<a href="#" id="emi-clear-dead-links">' + i18n.clearDeadLinkHistory + '</a></p>' +
                '</div>' +
                '<p style="padding: 20px; text-align: center; color: #666;">' + i18n.noActionablePosts + '</p>'
            );
            return;
        }

        displayPostsTable();
        $('#import-controls').show();
    }

    // Display posts with external files in a table
    function displayPostsTable() {
        const $postsList = $('#posts-list');
        $postsList.empty();

        // Show skipped dead-link posts notice if any
        if (skippedDeadLinkPosts > 0) {
            $postsList.append(
                '<div class="notice notice-warning inline" style="margin: 0 0 15px;">' +
                '<p>' + sprintf(i18n.postsSkippedDead, skippedDeadLinkPosts) + ' ' +
                '<a href="#" id="emi-clear-dead-links">' + i18n.clearDeadLinkHistory + '</a></p>' +
                '</div>'
            );
        }

        $postsList.append('<p><strong>' + sprintf(i18n.foundPostsWithFiles, postsWithFiles.length) + '</strong></p>');

        const $table = $('<table class="posts-table"></table>');
        const $thead = $('<thead><tr><th style="width: 80px;">' + i18n.thPostId + '</th><th style="width: 30%;">' + i18n.thPostTitle + '</th><th>' + i18n.thExternalFiles + '</th></tr></thead>');
        const $tbody = $('<tbody></tbody>');

        postsWithFiles.forEach(function(post, postIdx) {
            const $row = $('<tr class="post-row"></tr>');

            // Post ID
            const $idCell = $('<td></td>').text(post.post_id);

            // Post Title with link
            const $titleCell = $('<td></td>');
            const $titleLink = $('<a></a>')
                .attr('href', post.post_url)
                .attr('target', '_blank')
                .text(post.post_title);
            $titleCell.append($titleLink);

            // Files list with checkboxes
            const $filesCell = $('<td></td>');
            post.files.forEach(function(file, fileIdx) {
                const checkboxId = 'file-' + postIdx + '-' + fileIdx;
                const isImported = file.imported === true;
                const isDeadLink = file.dead_link === true;
                const $label = $('<label class="file-checkbox-label"></label>').attr('for', checkboxId);
                const $checkbox = $('<input type="checkbox" class="file-checkbox">')
                    .attr('id', checkboxId)
                    .attr('data-post-idx', postIdx)
                    .attr('data-file-idx', fileIdx)
                    .prop('checked', !isImported && !isDeadLink);

                if (isImported) {
                    $label.addClass('file-already-imported');
                }
                if (isDeadLink) {
                    $label.addClass('file-dead-link');
                }

                const $filename = $('<strong></strong>').text(file.filename);
                const $url = $('<span class="external-url"></span>').text(file.url);

                $label.append($checkbox).append(' ').append($filename);

                if (isImported) {
                    const $badge = $('<span class="imported-badge"></span>').text(sprintf(i18n.alreadyImported, file.attachment_id));
                    $label.append(' ').append($badge);
                }
                if (isDeadLink) {
                    var deadMsg = i18n.deadLink;
                    if (file.dead_link_message) {
                        deadMsg += ': ' + file.dead_link_message;
                    }
                    const $badge = $('<span class="dead-link-badge"></span>').text(deadMsg);
                    $label.append(' ').append($badge);
                }

                $label.append($url);
                $filesCell.append($label);
            });

            $row.append($idCell).append($titleCell).append($filesCell);
            $tbody.append($row);
        });

        $table.append($thead).append($tbody);
        $postsList.append($table);

        updateImportButton();
    }

    // Select all files (skip already imported and dead link files)
    $('#select-all-files-btn').on('click', function() {
        $('.file-checkbox').each(function() {
            var $label = $(this).closest('.file-checkbox-label');
            if (!$label.hasClass('file-already-imported') && !$label.hasClass('file-dead-link')) {
                $(this).prop('checked', true);
            }
        });
        updateImportButton();
    });

    // Deselect all files
    $('#deselect-all-files-btn').on('click', function() {
        $('.file-checkbox').prop('checked', false);
        updateImportButton();
    });

    // Update import button state
    $(document).on('change', '.file-checkbox', function() {
        updateImportButton();
    });

    function updateImportButton() {
        const checkedCount = $('.file-checkbox:checked').length;
        $('#import-selected-btn').prop('disabled', checkedCount === 0);
        $('#dry-run-btn').prop('disabled', checkedCount === 0);

        if (checkedCount > 0) {
            $('#import-selected-btn').text(sprintf(i18n.importSelectedCount, checkedCount));
            $('#dry-run-btn').text(sprintf(i18n.dryRunBtnCount, checkedCount));
        } else {
            $('#import-selected-btn').text(i18n.importSelectedFiles);
            $('#dry-run-btn').text(i18n.dryRunBtn);
        }
    }

    // Dry Run — check file sizes one by one with progress bar
    $('#dry-run-btn').on('click', function() {
        var dryRunItems = [];
        $('.file-checkbox:checked').each(function() {
            var postIdx = $(this).data('post-idx');
            var fileIdx = $(this).data('file-idx');
            var file = postsWithFiles[postIdx].files[fileIdx];
            dryRunItems.push({
                url: file.url,
                $checkbox: $(this)
            });
        });

        if (dryRunItems.length === 0) {
            alert(i18n.selectOneFile);
            return;
        }

        var $btn = $(this);
        var dryRunIndex = 0;
        var dryRunTotal = dryRunItems.length;
        var totalSize = 0;

        // Disable controls
        $btn.prop('disabled', true).text(i18n.checking);
        $('#import-selected-btn').prop('disabled', true);
        $('#select-all-files-btn').prop('disabled', true);
        $('#deselect-all-files-btn').prop('disabled', true);
        $('#dry-run-summary').hide().empty();

        // Show progress
        $('#dry-run-progress').slideDown();
        $('#dry-run-bar').css('width', '0%').text('0%');
        $('#dry-run-text').text(sprintf(i18n.filesChecked, 0, dryRunTotal));

        function checkNextFile() {
            if (dryRunIndex >= dryRunTotal) {
                // All done — show summary and re-enable controls
                $('#dry-run-summary')
                    .text(sprintf(i18n.dryRunTotal, dryRunTotal, formatBytes(totalSize)))
                    .show();
                $('#dry-run-progress').slideUp();
                $btn.prop('disabled', false);
                updateImportButton();
                return;
            }

            var item = dryRunItems[dryRunIndex];

            $.ajax({
                url: emiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'emi_dry_run',
                    nonce: emiAjax.nonces.dry_run,
                    urls: [item.url]
                },
                success: function(response) {
                    if (response.success) {
                        var info = response.data.files[item.url];
                        if (info) {
                            var $label = item.$checkbox.closest('.file-checkbox-label');
                            $label.find('.file-size-badge').remove();

                            var badgeClass = info.error ? 'file-size-badge file-size-error' : 'file-size-badge';
                            var $badge = $('<span></span>').addClass(badgeClass).text(info.size_formatted);
                            $label.find('strong').after(' ', $badge);

                            if (info.size && info.size > 0) {
                                totalSize += info.size;
                            }
                        }
                    }
                },
                complete: function() {
                    dryRunIndex++;
                    var pct = Math.round((dryRunIndex / dryRunTotal) * 100);
                    $('#dry-run-bar').css('width', pct + '%').text(pct + '%');
                    $('#dry-run-text').text(sprintf(i18n.filesChecked, dryRunIndex, dryRunTotal));

                    setTimeout(checkNextFile, 200);
                }
            });
        }

        checkNextFile();
    });

    // Helper: format bytes to human-readable
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    // Import selected files
    $('#import-selected-btn').on('click', function() {
        importQueue = [];

        $('.file-checkbox:checked').each(function() {
            const postIdx = $(this).data('post-idx');
            const fileIdx = $(this).data('file-idx');

            const post = postsWithFiles[postIdx];
            const file = post.files[fileIdx];

            importQueue.push({
                post_id: post.post_id,
                post_title: post.post_title,
                file: file
            });
        });

        if (importQueue.length === 0) {
            alert(i18n.selectOneFileImport);
            return;
        }

        // Disable controls
        $('#import-selected-btn').prop('disabled', true);
        $('#select-all-files-btn').prop('disabled', true);
        $('#deselect-all-files-btn').prop('disabled', true);
        $('.file-checkbox').prop('disabled', true);
        $('#scan-posts-btn').prop('disabled', true);

        // Reset and show import progress section
        $('#progress-bar').css('width', '0%').text('0%');
        $('#progress-text').text(sprintf(i18n.filesProcessed, 0, importQueue.length));
        $('#import-log').empty();
        $('#import-progress').slideDown();
        currentIndex = 0;

        updateProgress();
        processNextFile();
    });

    // Process files one by one
    function processNextFile() {
        if (currentIndex >= importQueue.length) {
            // All done
            completeImport();
            return;
        }

        const item = importQueue[currentIndex];

        addLog(sprintf(i18n.processing, item.file.filename, item.post_title), 'info');

        $.ajax({
            url: emiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'emi_import_file',
                nonce: emiAjax.nonces.import_file,
                url: item.file.url,
                post_id: item.post_id
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;

                    if (data.status === 'success') {
                        let message = '\u2713 ' + sprintf(i18n.importSuccess, item.file.filename, data.attachment_id);
                        if (data.url_replaced) {
                            message += ' - ' + i18n.urlReplaced;
                        }
                        addLog(message, 'success');
                    } else if (data.status === 'skipped') {
                        addLog('\u26A0 ' + sprintf(i18n.skippedFile, item.file.filename, data.message), 'skipped');
                    }
                } else {
                    addLog('\u2717 ' + sprintf(i18n.errorImporting, item.file.filename, response.data), 'error');
                }
            },
            error: function(xhr, status, error) {
                addLog('\u2717 ' + sprintf(i18n.ajaxError, item.file.filename, error), 'error');
            },
            complete: function() {
                currentIndex++;
                updateProgress();

                // Process next file after short delay
                setTimeout(processNextFile, 500);
            }
        });
    }

    // Update progress bar and text
    function updateProgress() {
        const total = importQueue.length;
        const processed = currentIndex;
        const percentage = Math.round((processed / total) * 100);

        $('#progress-bar').css('width', percentage + '%').text(percentage + '%');
        $('#progress-text').text(sprintf(i18n.filesProcessed, processed, total));
    }

    // Add log entry
    function addLog(message, type) {
        const $log = $('<div class="log-entry ' + type + '"></div>').text(message);
        $('#import-log').prepend($log);

        // Auto-scroll to top
        $('#import-log').scrollTop(0);
    }

    // Complete import process
    function completeImport() {
        addLog(i18n.importCompleted, 'success');

        // Re-enable controls
        setTimeout(function() {
            $('#import-selected-btn').prop('disabled', false);
            $('#select-all-files-btn').prop('disabled', false);
            $('#deselect-all-files-btn').prop('disabled', false);
            $('.file-checkbox').prop('disabled', false);
            $('#scan-posts-btn').prop('disabled', false);

            // Optionally rescan to show remaining files
            alert(i18n.importCompletedAlert);
        }, 1000);
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // --- Retry Failed Imports ---
    $('#emi-retry-failed-btn').on('click', function() {
        if (!confirm(i18n.confirmRetry)) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(i18n.loading);

        // Fetch all failed import entries
        $.ajax({
            url: emiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'emi_get_failed_imports',
                nonce: emiAjax.nonces.get_failed
            },
            success: function(response) {
                if (response.success && response.data.items.length > 0) {
                    var items = response.data.items;
                    var retryIndex = 0;
                    var retryTotal = items.length;

                    $btn.text(sprintf(i18n.retryingFiles, retryTotal));
                    $('#emi-retry-progress').slideDown();
                    $('#emi-retry-log').empty();
                    $('#emi-retry-bar').css('width', '0%').text('0%');
                    $('#emi-retry-text').text(sprintf(i18n.filesProcessed, 0, retryTotal));

                    function retryNext() {
                        if (retryIndex >= retryTotal) {
                            // All done
                            var $doneLog = $('<div class="log-entry success"></div>').text(i18n.retryCompleted);
                            $('#emi-retry-log').prepend($doneLog);
                            $btn.text(i18n.doneReload);
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                            return;
                        }

                        var item = items[retryIndex];
                        var $logEntry = $('<div class="log-entry"></div>').text(sprintf(i18n.processing, item.url, item.post_title));
                        $('#emi-retry-log').prepend($logEntry);

                        // First delete old error log entry, then re-import
                        $.ajax({
                            url: emiAjax.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'emi_delete_log_entry',
                                nonce: emiAjax.nonces.delete_log,
                                log_id: item.log_id
                            },
                            complete: function() {
                                // Now retry the import
                                $.ajax({
                                    url: emiAjax.ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'emi_import_file',
                                        nonce: emiAjax.nonces.import_file,
                                        url: item.url,
                                        post_id: item.post_id
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            var data = response.data;
                                            if (data.status === 'success') {
                                                var msg = '\u2713 ' + sprintf(i18n.retryImported, item.url, data.attachment_id);
                                                var $log = $('<div class="log-entry success"></div>').text(msg);
                                                $('#emi-retry-log').prepend($log);
                                            } else if (data.status === 'skipped') {
                                                var $log = $('<div class="log-entry skipped"></div>').text('\u26A0 ' + sprintf(i18n.skippedFile, item.url, data.message));
                                                $('#emi-retry-log').prepend($log);
                                            }
                                        } else {
                                            var $log = $('<div class="log-entry error"></div>').text('\u2717 ' + sprintf(i18n.retryFailed, item.url, response.data));
                                            $('#emi-retry-log').prepend($log);
                                        }
                                    },
                                    error: function(xhr, status, error) {
                                        var $log = $('<div class="log-entry error"></div>').text('\u2717 ' + sprintf(i18n.ajaxError, item.url, error));
                                        $('#emi-retry-log').prepend($log);
                                    },
                                    complete: function() {
                                        retryIndex++;
                                        var pct = Math.round((retryIndex / retryTotal) * 100);
                                        $('#emi-retry-bar').css('width', pct + '%').text(pct + '%');
                                        $('#emi-retry-text').text(sprintf(i18n.filesProcessed, retryIndex, retryTotal));

                                        setTimeout(retryNext, 500);
                                    }
                                });
                            }
                        });
                    }

                    retryNext();
                } else {
                    alert(i18n.noFailedImports);
                    $btn.prop('disabled', false).text(i18n.retryFailedBtn);
                }
            },
            error: function() {
                alert(i18n.failedFetchErrors);
                $btn.prop('disabled', false).text(i18n.retryFailedBtn);
            }
        });
    });

    // --- Clear dead link history ---
    $(document).on('click', '#emi-clear-dead-links', function(e) {
        e.preventDefault();
        if (!confirm(i18n.confirmClearDead)) {
            return;
        }

        var $link = $(this);
        $link.text(i18n.clearing);

        $.ajax({
            url: emiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'emi_clear_dead_links',
                nonce: emiAjax.nonces.clear_dead
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message + ' ' + i18n.pleaseRescan);
                    $link.closest('.notice').fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert(i18n.error + ' ' + response.data);
                    $link.text(i18n.clearDeadLinkHistory);
                }
            },
            error: function() {
                alert(i18n.ajaxErrorRetry);
                $link.text(i18n.clearDeadLinkHistory);
            }
        });
    });

    // --- Select2 for file types on settings page ---
    if ($('#emi_file_types_select').length) {
        $('#emi_file_types_select').select2({
            tags: true,
            tokenSeparators: [',', ' '],
            placeholder: 'Select or type file extensions...',
            allowClear: true,
            createTag: function(params) {
                var term = $.trim(params.term).toLowerCase().replace(/^\./, '');
                if (term === '') {
                    return null;
                }
                return {
                    id: term,
                    text: term,
                    newTag: true
                };
            }
        });

        // Sync Select2 values to hidden input on change
        $('#emi_file_types_select').on('change', function() {
            var values = $(this).val();
            $('#emi_file_types_hidden').val(values ? values.join(',') : '');
        });
    }

    // --- Select2 for external server URLs on settings page ---
    if ($('#emi_external_urls_select').length) {
        $('#emi_external_urls_select').select2({
            tags: true,
            tokenSeparators: [','],
            placeholder: 'Type external server URLs and press Enter...',
            allowClear: true,
            createTag: function(params) {
                var term = $.trim(params.term);
                if (term === '') {
                    return null;
                }
                // Remove trailing slash for consistency
                term = term.replace(/\/+$/, '');
                return {
                    id: term,
                    text: term,
                    newTag: true
                };
            }
        });

        // Sync Select2 values to hidden input on change
        $('#emi_external_urls_select').on('change', function() {
            var values = $(this).val();
            $('#emi_external_urls_hidden').val(values ? values.join(',') : '');
        });
    }
});
