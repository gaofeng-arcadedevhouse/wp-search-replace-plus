jQuery(document).ready(function($) {
    'use strict';
    
    var WTSR = {
        searchResults: [],
        selectedItems: [],
        currentPage: 1,
        resultsPerPage: 20,
        totalResults: 0,
        isLoading: false,
        
        init: function() {
            this.bindEvents();
            this.initializeInterface();
        },
        
        bindEvents: function() {
            // Search button
            $('#wtsr-search-btn').on('click', this.handleSearch.bind(this));
            
            // Replace buttons
            $(document).on('click', '#wtsr-replace-selected', this.handleReplace.bind(this));
            
            // Selection buttons
            $(document).on('click', '#wtsr-select-all', this.selectAllVisible.bind(this));
            $(document).on('click', '#wtsr-select-none', this.selectNone.bind(this));
            
            // Pagination controls
            $(document).on('click', '.wtsr-pagination-btn', this.handlePagination.bind(this));
            $(document).on('change', '#wtsr-results-per-page', this.handleResultsPerPageChange.bind(this));
            
            // Individual item selection
            $(document).on('change', '.wtsr-item-checkbox', this.handleItemSelection.bind(this));
            
            // Result item expansion (throttled for performance)
            $(document).on('click', '.wtsr-result-header', this.throttle(this.toggleResultExpansion.bind(this), 100));
            
            // Preview buttons
            $(document).on('click', '.wtsr-preview-btn', this.showPreview.bind(this));
            
            // Modal events
            $(document).on('click', '.wtsr-modal-close, #wtsr-cancel-replace', this.closeModal.bind(this));
            $(document).on('click', '#wtsr-confirm-replace', this.confirmReplace.bind(this));
            
            // Prevent modal close on content click
            $(document).on('click', '.wtsr-modal-content', function(e) {
                e.stopPropagation();
            });
            
            // Close modal on background click
            $(document).on('click', '.wtsr-modal', this.closeModal.bind(this));
            
            // Performance optimization: debounce search input
            $('#search_text').on('input', this.debounce(this.onSearchInputChange.bind(this), 300));
        },
        
        initializeInterface: function() {
            // Hide results section initially
            $('#wtsr-results').hide();
            $('#wtsr-progress').hide();
            this.clearMessages();
            
            // Add pagination controls
            this.initializePaginationControls();
        },
        
        initializePaginationControls: function() {
            var paginationHtml = '<div class="wtsr-pagination-controls" style="display: none;">' +
                '<div class="wtsr-pagination-info">' +
                    '<label for="wtsr-results-per-page">Per page: </label>' +
                    '<select id="wtsr-results-per-page">' +
                        '<option value="10">10</option>' +
                        '<option value="20" selected>20</option>' +
                        '<option value="50">50</option>' +
                        '<option value="100">100</option>' +
                    '</select>' +
                    '<span class="wtsr-results-count"></span>' +
                '</div>' +
                '<div class="wtsr-pagination-buttons">' +
                    '<button type="button" class="button wtsr-pagination-btn" data-action="first" disabled>First</button>' +
                    '<button type="button" class="button wtsr-pagination-btn" data-action="prev" disabled>Previous</button>' +
                    '<span class="wtsr-page-info">Page <span class="current-page">1</span> of <span class="total-pages">1</span></span>' +
                    '<button type="button" class="button wtsr-pagination-btn" data-action="next" disabled>Next</button>' +
                    '<button type="button" class="button wtsr-pagination-btn" data-action="last" disabled>Last</button>' +
                '</div>' +
            '</div>';
            
            $('#wtsr-results-table').before(paginationHtml);
        },
        
        onSearchInputChange: function() {
            // Clear previous results when search text changes
            if ($('#search_text').val().trim() === '') {
                this.resetResults();
            }
        },
        
        resetResults: function() {
            this.searchResults = [];
            this.selectedItems = [];
            this.currentPage = 1;
            this.totalResults = 0;
            $('#wtsr-results').hide();
            $('.wtsr-pagination-controls').hide();
        },
        
        handlePagination: function(e) {
            e.preventDefault();
            
            if (this.isLoading) {
                return;
            }
            
            var action = $(e.target).data('action');
            var totalPages = this.getTotalPages();
            
            switch (action) {
                case 'first':
                    if (this.currentPage > 1) {
                        this.currentPage = 1;
                        this.displayCurrentPage();
                    }
                    break;
                case 'prev':
                    if (this.currentPage > 1) {
                        this.currentPage--;
                        this.displayCurrentPage();
                    }
                    break;
                case 'next':
                    if (this.currentPage < totalPages) {
                        this.currentPage++;
                        this.displayCurrentPage();
                    }
                    break;
                case 'last':
                    if (this.currentPage < totalPages) {
                        this.currentPage = totalPages;
                        this.displayCurrentPage();
                    }
                    break;
            }
        },
        
        handleResultsPerPageChange: function() {
            this.resultsPerPage = parseInt($('#wtsr-results-per-page').val());
            this.currentPage = 1;
            this.displayCurrentPage();
        },
        
        getTotalPages: function() {
            return Math.ceil(this.totalResults / this.resultsPerPage);
        },
        
        updatePaginationControls: function() {
            var totalPages = this.getTotalPages();
            var $controls = $('.wtsr-pagination-controls');
            
            if (this.totalResults <= this.resultsPerPage) {
                $controls.hide();
                return;
            }
            
            $controls.show();
            
            // Update page info
            $('.current-page').text(this.currentPage);
            $('.total-pages').text(totalPages);
            
            // Update results count
            var startResult = ((this.currentPage - 1) * this.resultsPerPage) + 1;
            var endResult = Math.min(this.currentPage * this.resultsPerPage, this.totalResults);
            $('.wtsr-results-count').text('Showing ' + startResult + '-' + endResult + ' of ' + this.totalResults + ' results');
            
            // Update button states
            $('[data-action="first"], [data-action="prev"]').prop('disabled', this.currentPage <= 1);
            $('[data-action="next"], [data-action="last"]').prop('disabled', this.currentPage >= totalPages);
        },
        
        displayCurrentPage: function() {
            var startIndex = (this.currentPage - 1) * this.resultsPerPage;
            var endIndex = startIndex + this.resultsPerPage;
            var currentPageResults = this.getAllResults().slice(startIndex, endIndex);
            
            this.renderResults(currentPageResults, startIndex);
            this.updatePaginationControls();
            this.updateReplaceButtonState();
        },
        
        getAllResults: function() {
            // First try to get results from stored search results
            var allResults = [];
            
            if (this.searchResults.database && Array.isArray(this.searchResults.database)) {
                allResults = allResults.concat(this.searchResults.database);
            }
            if (this.searchResults.files && Array.isArray(this.searchResults.files)) {
                allResults = allResults.concat(this.searchResults.files);
            }
            
            // If we have stored results, return them
            if (allResults.length > 0) {
                console.log('WTSR: Using stored search results:', allResults.length);
                return allResults;
            }
            
            // Fallback: Get results from DOM if stored results not available
            console.log('WTSR: Fallback to DOM-based results');
            var domResults = [];
            
            $('.wtsr-result-item').each(function() {
                var $item = $(this);
                var index = parseInt($item.data('index'));
                var type = $item.data('type');
                var title = $item.find('.wtsr-item-title').text().trim();
                var editUrl = $item.find('.wtsr-edit-link').attr('href');
                var viewUrl = $item.find('.wtsr-view-link').attr('href');
                var postType = $item.data('post-type') || type;
                
                domResults[index] = {
                    type: type,
                    post_type: postType,
                    title: title,
                    edit_url: editUrl,
                    view_url: viewUrl
                };
            });
            
            return domResults;
        },
        
        renderResults: function(results, startIndex) {
            var self = this;
            var resultsHtml = '';
            
            if (results.length === 0) {
                $('#wtsr-results-table').html('<p>No results on current page</p>');
                return;
            }
            
            // Use document fragment for better performance
            results.forEach(function(item, index) {
                var globalIndex = startIndex + index;
                resultsHtml += self.renderResultItem(item, globalIndex);
            });
            
            // Batch DOM update
            $('#wtsr-results-table').html(resultsHtml);
            
            // Update checkbox states based on selection
            this.updateCheckboxStates();
        },
        
        updateCheckboxStates: function() {
            var self = this;
            $('.wtsr-item-checkbox').each(function() {
                var index = parseInt($(this).data('index'));
                $(this).prop('checked', self.selectedItems.indexOf(index) !== -1);
            });
        },
        
        selectAllVisible: function(e) {
            e.preventDefault();
            
            var self = this;
            $('.wtsr-item-checkbox').each(function() {
                var index = parseInt($(this).data('index'));
                $(this).prop('checked', true);
                
                if (self.selectedItems.indexOf(index) === -1) {
                    self.selectedItems.push(index);
                }
            });
            
            this.updateReplaceButtonState();
        },
        
        // Utility functions for performance
        debounce: function(func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function() {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        throttle: function(func, limit) {
            var inThrottle;
            return function() {
                var context = this;
                var args = arguments;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(function() {
                        inThrottle = false;
                    }, limit);
                }
            };
        },
        
        handleSearch: function(e) {
            e.preventDefault();
            
            var searchText = $('#search_text').val().trim();
            var searchScope = [];
            
            $('input[name="search_scope[]"]:checked').each(function() {
                searchScope.push($(this).val());
            });
            
            console.log('WTSR: Search initiated with scope:', searchScope, 'and text:', searchText);
            
            if (!searchText) {
                this.showMessage('Please enter search text', 'error');
                return;
            }
            
            if (searchScope.length === 0) {
                this.showMessage('Please select search scope', 'error');
                return;
            }
            
            // Just show a warning for disabled ACF but don't block search
            if (searchScope.includes('acf') && $('#search_acf').is(':disabled')) {
                this.showMessage('ACF field search requires Advanced Custom Fields plugin - ACF option will be ignored', 'warning');
                // Remove ACF from scope instead of blocking
                searchScope = searchScope.filter(function(scope) {
                    return scope !== 'acf';
                });
                if (searchScope.length === 0) {
                    this.showMessage('No valid search scope selected', 'error');
                    return;
                }
            }
            
            this.performSearch();
        },
        
        performSearch: function() {
            var self = this;
            var $button = $('#wtsr-search-btn');
            var originalText = $button.text();
            
            // Prevent multiple simultaneous searches
            if (this.isLoading) {
                return;
            }
            
            this.isLoading = true;
            
            // Show loading state
            $button.prop('disabled', true).html('<span class="wtsr-spinner"></span>Searching...');
            this.showProgress(0, 'Searching...');
            this.clearMessages();
            
            var formData = {
                action: 'wtsr_search',
                nonce: wtsr_ajax.nonce,
                search_text: $('#search_text').val(),
                search_scope: [],
                case_sensitive: $('#case_sensitive').is(':checked') ? '1' : '0',
                regex_mode: $('#regex_mode').is(':checked') ? '1' : '0',
                whole_words: $('#whole_words').is(':checked') ? '1' : '0',
                // Add performance options
                per_page: 1000, // Fetch more results initially
                max_files: parseInt($('#wtsr-max-files').val()) || 500,
                db_limit: parseInt($('#wtsr-db-limit').val()) || 100,
                skip_large_files: $('#wtsr-skip-large-files').is(':checked') ? '1' : '0',
                enable_cache: $('#wtsr-enable-cache').is(':checked') ? '1' : '0'
            };
            
            $('input[name="search_scope[]"]:checked').each(function() {
                formData.search_scope.push($(this).val());
            });
            
            // Debug: Log the form data being sent
            console.log('WTSR: Sending search request with data:', formData);
            console.log('WTSR: AJAX URL:', wtsr_ajax.ajax_url);
            
            $.post(wtsr_ajax.ajax_url, formData)
                .done(function(response) {
                    console.log('WTSR: Search response received:', response);
                    
                    if (response && response.success) {
                        self.displaySearchResults(response.data);
                        self.showMessage('Search completed', 'success');
                    } else {
                        var errorMessage = 'Unknown error';
                        if (response && response.data && response.data.message) {
                            errorMessage = response.data.message;
                        } else if (response && response.message) {
                            errorMessage = response.message;
                        }
                        console.error('WTSR: Search failed with error:', errorMessage);
                        self.showMessage(errorMessage, 'error');
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error('WTSR: AJAX request failed:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        xhr: xhr
                    });
                    
                    var errorMessage = 'Search request failed, please try again';
                    
                    // Try to parse error response
                    if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMessage = errorResponse.data.message;
                            }
                        } catch (e) {
                            // If response is not JSON, show first 100 chars
                            if (xhr.responseText.length > 0) {
                                errorMessage += ' (Error details: ' + xhr.responseText.substring(0, 100) + '...)';
                            }
                        }
                    }
                    
                    self.showMessage(errorMessage, 'error');
                })
                .always(function() {
                    console.log('WTSR: Search request completed');
                    $button.prop('disabled', false).text(originalText);
                    self.hideProgress();
                    self.isLoading = false;
                });
        },
        
        displaySearchResults: function(data) {
            // Store search results
            this.searchResults = data.results;
            this.selectedItems = [];
            this.currentPage = 1;
            
            // Calculate total results
            var databaseCount = (data.results.database && Array.isArray(data.results.database)) ? data.results.database.length : 0;
            var filesCount = (data.results.files && Array.isArray(data.results.files)) ? data.results.files.length : 0;
            this.totalResults = databaseCount + filesCount;
            
            // Update summary
            $('#wtsr-summary-text').text(data.summary || 'Found ' + this.totalResults + ' matches');
            
            if (this.totalResults === 0) {
                $('#wtsr-results-table').html('<p>No matching results found</p>');
                $('#wtsr-results').show();
                $('.wtsr-pagination-controls').hide();
                return;
            }
            
            // Display first page
            this.displayCurrentPage();
            $('#wtsr-results').show();
            
            // Update replace button state
            this.updateReplaceButtonState();
        },
        
        renderResultItem: function(item, index) {
            var self = this;
            var itemId = 'wtsr-item-' + index;
            var typeLabel = this.getTypeLabel(item.type);
            var statusLabel = this.getStatusLabel(item);
            
            var html = '<div class="wtsr-result-item" data-index="' + index + '" data-type="' + this.escapeHtml(item.type) + '" data-post-type="' + this.escapeHtml(item.post_type || item.type) + '">';
            
            // Header
            html += '<div class="wtsr-result-header">';
            html += '<input type="checkbox" class="wtsr-item-checkbox wtsr-checkbox" id="' + itemId + '" data-index="' + index + '">';
            html += '<div class="wtsr-result-content-wrapper">';
            html += '<div class="wtsr-result-title">';
            html += '<span class="wtsr-item-title">' + this.escapeHtml(item.title || 'No Title') + '</span>';
            html += '</div>';
            html += '<div class="wtsr-result-meta">';
            html += typeLabel + ' | ' + statusLabel + ' | ' + item.match_count + ' matches';
            if (item.edit_url) {
                html += ' | <a href="' + item.edit_url + '" target="_blank" class="wtsr-edit-link">Edit</a>';
            }
            if (item.view_url) {
                html += ' | <a href="' + item.view_url + '" target="_blank" class="wtsr-view-link">View</a>';
            }
            html += '</div>';
            html += '</div>';
            html += '<div class="wtsr-result-actions">';
            html += '<button type="button" class="button wtsr-preview-btn" data-index="' + index + '">Preview</button>';
            html += '</div>';
            html += '<div style="clear: both;"></div>';
            html += '</div>';
            
            // Content (initially hidden)
            html += '<div class="wtsr-result-content" id="content-' + itemId + '">';
            html += this.buildMatchesHtml(item);
            html += '</div>';
            
            html += '</div>';
            
            return html;
        },
        
        buildMatchesHtml: function(item) {
            var self = this;
            var html = '';
            
            if (item.matches && item.matches.length > 0) {
                item.matches.forEach(function(match) {
                    html += '<div class="wtsr-match-preview">';
                    
                    if (item.type === 'file') {
                        // File match display
                        html += '<strong>Line ' + match.line_number + ':</strong><br>';
                        html += '<div class="wtsr-file-line">';
                        html += '<span class="wtsr-file-line-number">' + match.line_number + '</span>';
                        html += '<span class="wtsr-file-line-content">' + self.escapeHtml(match.line_content) + '</span>';
                        html += '</div>';
                    } else {
                        // Database match display
                        html += '<strong>' + self.getFieldLabel(match.field) + ':</strong><br>';
                        html += self.escapeHtml(match.content.substring(0, 200));
                        if (match.content.length > 200) {
                            html += '...';
                        }
                    }
                    
                    html += '</div>';
                });
            }
            
            return html;
        },
        
        getTypeLabel: function(type) {
            var labels = {
                'post': 'Post',
                'page': 'Page',
                'acf': 'ACF Field',
                'meta': 'Custom Field',
                'comment': 'Comment',
                'file': 'File'
            };
            return labels[type] || type;
        },
        
        getStatusLabel: function(item) {
            if (item.post_status) {
                var statusLabels = {
                    'publish': 'Published',
                    'draft': 'Draft',
                    'private': 'Private'
                };
                return statusLabels[item.post_status] || item.post_status;
            }
            
            if (item.file_type) {
                var typeLabels = {
                    'theme': 'Theme File',
                    'child_theme': 'Child Theme File',
                    'plugin': 'Plugin File'
                };
                return typeLabels[item.file_type] || 'File';
            }
            
            return 'Normal';
        },
        
        getFieldLabel: function(field) {
            var labels = {
                'post_title': 'Title',
                'post_content': 'Content',
                'post_excerpt': 'Excerpt',
                'meta_value': 'Field Value',
                'comment_content': 'Comment Content',
                'comment_author': 'Comment Author'
            };
            return labels[field] || field;
        },
        
        toggleResultExpansion: function(e) {
            if ($(e.target).is('input, button, a')) {
                return;
            }
            
            var $header = $(e.currentTarget);
            var $content = $header.siblings('.wtsr-result-content');
            
            $content.slideToggle(200);
        },
        
        handleItemSelection: function(e) {
            var index = parseInt($(e.target).data('index'));
            var isChecked = $(e.target).is(':checked');
            
            if (isChecked) {
                if (this.selectedItems.indexOf(index) === -1) {
                    this.selectedItems.push(index);
                }
            } else {
                var itemIndex = this.selectedItems.indexOf(index);
                if (itemIndex > -1) {
                    this.selectedItems.splice(itemIndex, 1);
                }
            }
            
            this.updateReplaceButtonState();
        },
        
        selectNone: function(e) {
            e.preventDefault();
            
            $('.wtsr-item-checkbox').prop('checked', false);
            this.selectedItems = [];
            this.updateReplaceButtonState();
        },
        
        updateReplaceButtonState: function() {
            var $button = $('#wtsr-replace-selected');
            var hasSelection = this.selectedItems.length > 0;
            
            $button.prop('disabled', !hasSelection);
            
            if (hasSelection) {
                $button.text('Replace Selected (' + this.selectedItems.length + ')');
            } else {
                $button.text('Replace Selected');
            }
        },
        
        handleReplace: function(e) {
            e.preventDefault();
            
            if (this.selectedItems.length === 0) {
                this.showMessage('Please select items to replace', 'warning');
                return;
            }
            
            var replaceText = $('#replace_text').val();
            
            // Show confirmation modal
            this.showReplaceConfirmation(replaceText);
        },
        
        showReplaceConfirmation: function(replaceText) {
            var searchText = $('#search_text').val();
            var selectedCount = this.selectedItems.length;
            
            var confirmationHtml = '<div class="wtsr-confirmation-details">';
            confirmationHtml += '<h4>Operation Details:</h4>';
            confirmationHtml += '<ul>';
            confirmationHtml += '<li><strong>Search Text:</strong> ' + this.escapeHtml(searchText) + '</li>';
            confirmationHtml += '<li><strong>Replace Text:</strong> ' + (replaceText ? this.escapeHtml(replaceText) : '<em>(delete)</em>') + '</li>';
            confirmationHtml += '<li><strong>Affected Items:</strong> ' + selectedCount + '</li>';
            confirmationHtml += '</ul>';
            confirmationHtml += '</div>';
            
            $('#wtsr-confirmation-details').html(confirmationHtml);
            $('#wtsr-modal').addClass('wtsr-modal-show');
        },
        
        confirmReplace: function(e) {
            e.preventDefault();
            this.closeModal();
            this.performReplace();
        },
        
        performReplace: function() {
            var self = this;
            var $button = $('#wtsr-replace-selected');
            var originalText = $button.text();
            
            // Get replace text from input field
            var replaceText = $('#replace_text').val();
            
            // Show loading state
            $button.prop('disabled', true).html('<span class="wtsr-spinner"></span>Replacing...');
            this.showProgress(0, 'Replacing...');
            this.clearMessages();
            
            // Prepare selected items data - fix data preparation
            var selectedItemsData = [];
            var allResults = [];
            
            if (this.searchResults.database && Array.isArray(this.searchResults.database)) {
                allResults = allResults.concat(this.searchResults.database);
            }
            if (this.searchResults.files && Array.isArray(this.searchResults.files)) {
                allResults = allResults.concat(this.searchResults.files);
            }
            
            console.log('WTSR: All results length:', allResults.length);
            console.log('WTSR: Selected items:', this.selectedItems);
            
            this.selectedItems.forEach(function(index) {
                if (allResults[index]) {
                    // Ensure we're sending clean JSON data
                    var itemData = JSON.stringify(allResults[index]);
                    selectedItemsData.push(itemData);
                    console.log('WTSR: Added item', index, ':', itemData.substring(0, 100) + '...');
                } else {
                    console.warn('WTSR: Item at index', index, 'not found in results');
                }
            });
            
            if (selectedItemsData.length === 0) {
                console.error('WTSR: No valid items to replace');
                this.showMessage('No valid items to replace', 'error');
                $button.prop('disabled', false).text(originalText);
                this.hideProgress();
                return;
            }
            
            var formData = {
                action: 'wtsr_replace',
                nonce: wtsr_ajax.nonce,
                replace_data: JSON.stringify(selectedItemsData),
                replace_text: replaceText,
                case_sensitive: $('#case_sensitive').is(':checked') ? '1' : '0',
                regex_mode: $('#regex_mode').is(':checked') ? '1' : '0',
                whole_words: $('#whole_words').is(':checked') ? '1' : '0'
            };
            
            console.log('WTSR: Sending replace request with data:', {
                action: formData.action,
                replace_data: formData.replace_data.substring(0, 100) + '...',
                replace_text: formData.replace_text,
                case_sensitive: formData.case_sensitive,
                regex_mode: formData.regex_mode,
                whole_words: formData.whole_words
            });
            
            $.post(wtsr_ajax.ajax_url, formData)
                .done(function(response) {
                    console.log('WTSR: Replace response received:', response);
                    
                    if (response && response.success) {
                        // Get replaced items details BEFORE removing them from DOM
                        var replacedItemsDetails = self.getReplacedItemsDetails();
                        
                        // Show detailed replacement results
                        self.showReplacementResults(
                            response.data.results || response.data,
                            formData.replace_text,
                            replacedItemsDetails  // Pass the details directly
                        );
                        
                        // Remove replaced items from results
                        self.removeReplacedItems();
                        
                        // Reset selection
                        self.selectedItems = [];
                        self.updateReplaceButtonState();
                        
                    } else {
                        var errorMessage = 'Unknown error';
                        if (response && response.data && response.data.message) {
                            errorMessage = response.data.message;
                        } else if (response && response.message) {
                            errorMessage = response.message;
                        }
                        console.error('WTSR: Replace failed with error:', errorMessage);
                        self.showMessage(errorMessage, 'error');
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error('WTSR: Replace request failed:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        xhr: xhr
                    });
                    
                    var errorMessage = 'Replace request failed, please try again';
                    
                    // Try to parse error response
                    if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMessage = errorResponse.data.message;
                            }
                        } catch (e) {
                            // If response is not JSON, show first 100 chars
                            if (xhr.responseText.length > 0) {
                                errorMessage += ' (Error details: ' + xhr.responseText.substring(0, 100) + '...)';
                            }
                        }
                    }
                    
                    self.showMessage(errorMessage, 'error');
                })
                .always(function() {
                    console.log('WTSR: Replace request completed');
                    $button.prop('disabled', false).text(originalText);
                    self.hideProgress();
                });
        },
        
        removeReplacedItems: function() {
            var self = this;
            
            // Sort indices in descending order to remove from end to beginning
            var sortedIndices = this.selectedItems.slice().sort(function(a, b) {
                return b - a;
            });
            
            sortedIndices.forEach(function(index) {
                $('.wtsr-result-item[data-index="' + index + '"]').fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // Update summary
            setTimeout(function() {
                var remainingItems = $('.wtsr-result-item').length;
                if (remainingItems === 0) {
                    $('#wtsr-results-table').html('<p>All matching items have been processed</p>');
                    $('#wtsr-summary-text').text('No more matching items');
                }
            }, 350);
        },
        
        showPreview: function(e) {
            e.preventDefault();
            var index = parseInt($(e.target).data('index'));
            
            // This would show a detailed preview modal
            // Implementation would depend on specific requirements
            alert('Preview feature will be implemented in future versions');
        },
        
        closeModal: function(e) {
            if (e && $(e.target).closest('.wtsr-modal-content').length && !$(e.target).hasClass('wtsr-modal-close') && e.target.id !== 'wtsr-cancel-replace') {
                return;
            }
            $('#wtsr-modal').removeClass('wtsr-modal-show');
            $('#wtsr-results-modal').removeClass('wtsr-modal-show');
        },
        
        showProgress: function(percent, text) {
            $('#wtsr-progress').show();
            $('.wtsr-progress-fill').css('width', percent + '%');
            $('.wtsr-progress-text').text(text || '');
        },
        
        hideProgress: function() {
            $('#wtsr-progress').hide();
        },
        
        showMessage: function(message, type) {
            type = type || 'info';
            
            var messageHtml = '<div class="wtsr-message ' + type + '">' + this.escapeHtml(message) + '</div>';
            $('#wtsr-messages').append(messageHtml);
            
            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(function() {
                    $('#wtsr-messages .wtsr-message.' + type + ':last').fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
            
            // Scroll to messages
            $('html, body').animate({
                scrollTop: $('#wtsr-messages').offset().top - 100
            }, 300);
        },
        
        clearMessages: function() {
            $('#wtsr-messages').empty();
        },
        
        escapeHtml: function(text) {
            if (!text) return '';
            
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return text.replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        },
        
        showReplacementResults: function(results, searchText, replaceText, replacedItemsDetails) {
            var self = this;
            
            // Handle different response data structures
            var totalReplacements = 0;
            if (results && typeof results === 'object') {
                totalReplacements = results.total_replacements || 
                                  (results.database_items || 0) + (results.file_items || 0) ||
                                  replacedItemsDetails.length;
            } else {
                totalReplacements = replacedItemsDetails.length;
            }
            
            console.log('WTSR: Showing replacement results:', {
                results: results,
                totalReplacements: totalReplacements,
                replacedItemsCount: replacedItemsDetails.length
            });
            
            // Create results modal HTML
            var modalHtml = '<div id="wtsr-results-modal" class="wtsr-modal">' +
                '<div class="wtsr-modal-content">' +
                    '<div class="wtsr-modal-header">' +
                        '<h3>Replacement Results</h3>' +
                        '<button type="button" class="wtsr-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="wtsr-modal-body">' +
                        '<div class="wtsr-results-summary">' +
                            '<h4>Operation Summary</h4>' +
                            '<p><strong>Search Text:</strong> ' + this.escapeHtml(searchText) + '</p>' +
                            '<p><strong>Replace Text:</strong> ' + (replaceText ? this.escapeHtml(replaceText) : '<em>(deleted)</em>') + '</p>' +
                            '<p><strong>Total Items Replaced:</strong> ' + totalReplacements + '</p>' +
                        '</div>' +
                        '<div class="wtsr-replaced-items">' +
                            '<h4>Replaced Items (' + replacedItemsDetails.length + ')</h4>' +
                            '<div id="wtsr-replaced-items-list"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wtsr-modal-footer">' +
                        '<button type="button" id="wtsr-close-results" class="button button-primary">Close</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
            
            // Remove existing results modal
            $('#wtsr-results-modal').remove();
            
            // Add modal to body
            $('body').append(modalHtml);
            
            // Show modal with CSS class
            $('#wtsr-results-modal').addClass('wtsr-modal-show');
            
            // Populate replaced items list
            this.populateReplacedItemsList(replacedItemsDetails);
            
            // Bind close events
            $('#wtsr-results-modal .wtsr-modal-close, #wtsr-close-results').on('click', function() {
                $('#wtsr-results-modal').removeClass('wtsr-modal-show');
                setTimeout(function() {
                    $('#wtsr-results-modal').remove();
                }, 300); // Allow time for fade out animation
            });
            
            // Note: Removed background click close functionality as requested
            // Users can only close the modal via the close button or X button
        },
        
        populateReplacedItemsList: function(replacedItemsDetails) {
            var self = this;
            var itemsHtml = '';
            
            console.log('WTSR: Populating replaced items list with:', replacedItemsDetails);
            
            if (!replacedItemsDetails || replacedItemsDetails.length === 0) {
                itemsHtml = '<p>No detailed information available about replaced items.</p>';
            } else {
                itemsHtml = '<div class="wtsr-replaced-items-table">';
                itemsHtml += '<table class="widefat">';
                itemsHtml += '<thead><tr><th>Type</th><th>Title</th><th>Actions</th></tr></thead>';
                itemsHtml += '<tbody>';
                
                replacedItemsDetails.forEach(function(item, index) {
                    console.log('WTSR: Processing replaced item:', index, item);
                    itemsHtml += '<tr>';
                    itemsHtml += '<td>' + self.getItemTypeLabel(item.type, item.post_type) + '</td>';
                    itemsHtml += '<td>' + self.escapeHtml(item.title || 'Untitled') + '</td>';
                    itemsHtml += '<td>';
                    
                    if (item.edit_url) {
                        itemsHtml += '<a href="' + item.edit_url + '" target="_blank" class="button button-small">Edit</a> ';
                    }
                    
                    if (item.view_url && (item.type === 'post' || item.type === 'page')) {
                        itemsHtml += '<a href="' + item.view_url + '" target="_blank" class="button button-small">View</a>';
                    }
                    
                    if (!item.edit_url && !item.view_url) {
                        itemsHtml += '<span class="description">No actions available</span>';
                    }
                    
                    itemsHtml += '</td>';
                    itemsHtml += '</tr>';
                });
                
                itemsHtml += '</tbody></table>';
                itemsHtml += '</div>';
            }
            
            $('#wtsr-replaced-items-list').html(itemsHtml);
            console.log('WTSR: Replaced items list populated successfully');
        },
        
        getReplacedItemsDetails: function() {
            var self = this;
            var replacedItems = [];
            var allResults = this.getAllResults();
            
            console.log('WTSR: Getting replaced items details');
            console.log('WTSR: Selected items:', this.selectedItems);
            console.log('WTSR: All results length:', allResults.length);
            
            this.selectedItems.forEach(function(index) {
                if (allResults[index]) {
                    replacedItems.push(allResults[index]);
                    console.log('WTSR: Added replaced item:', allResults[index]);
                } else {
                    console.log('WTSR: No result found for index:', index);
                }
            });
            
            console.log('WTSR: Final replaced items:', replacedItems);
            
            return replacedItems;
        },
        
        getItemTypeLabel: function(type, postType) {
            if (type === 'acf') {
                return 'ACF Field';
            } else if (type === 'post') {
                switch (postType) {
                    case 'post':
                        return 'Post';
                    case 'page':
                        return 'Page';
                    case 'widget':
                        return 'Widget';
                    default:
                        return postType.charAt(0).toUpperCase() + postType.slice(1);
                }
            } else if (type === 'file') {
                return 'Theme File';
            }
            return type.charAt(0).toUpperCase() + type.slice(1);
        }
    };
    
    // Initialize the plugin
    WTSR.init();
}); 