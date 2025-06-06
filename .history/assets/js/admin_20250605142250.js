jQuery(document).ready(function($) {
    'use strict';
    
    var WTSR = {
        searchResults: [],
        selectedItems: [],
        
        init: function() {
            this.bindEvents();
            this.initializeInterface();
        },
        
        bindEvents: function() {
            // Search button
            $('#wtsr-search-btn').on('click', this.handleSearch.bind(this));
            
            // Backup button
            $('#wtsr-backup-btn').on('click', this.handleBackup.bind(this));
            
            // Replace buttons
            $(document).on('click', '#wtsr-replace-selected', this.handleReplace.bind(this));
            
            // Selection buttons
            $(document).on('click', '#wtsr-select-all', this.selectAll.bind(this));
            $(document).on('click', '#wtsr-select-none', this.selectNone.bind(this));
            
            // Individual item selection
            $(document).on('change', '.wtsr-item-checkbox', this.handleItemSelection.bind(this));
            
            // Result item expansion
            $(document).on('click', '.wtsr-result-header', this.toggleResultExpansion.bind(this));
            
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
        },
        
        initializeInterface: function() {
            // Hide results section initially
            $('#wtsr-results').hide();
            $('#wtsr-progress').hide();
            this.clearMessages();
        },
        
        handleSearch: function(e) {
            e.preventDefault();
            
            var searchText = $('#search_text').val().trim();
            var searchScope = [];
            
            $('input[name="search_scope[]"]:checked').each(function() {
                searchScope.push($(this).val());
            });
            
            if (!searchText) {
                this.showMessage('请输入搜索文本', 'error');
                return;
            }
            
            if (searchScope.length === 0) {
                this.showMessage('请选择搜索范围', 'error');
                return;
            }
            
            this.performSearch();
        },
        
        performSearch: function() {
            var self = this;
            var $button = $('#wtsr-search-btn');
            var originalText = $button.text();
            
            // Show loading state
            $button.prop('disabled', true).html('<span class="wtsr-spinner"></span>' + wtsr_ajax.strings.searching);
            this.showProgress(0, wtsr_ajax.strings.searching);
            this.clearMessages();
            
            var formData = {
                action: 'wtsr_search',
                nonce: wtsr_ajax.nonce,
                search_text: $('#search_text').val(),
                search_scope: [],
                case_sensitive: $('#case_sensitive').is(':checked') ? '1' : '0',
                regex_mode: $('#regex_mode').is(':checked') ? '1' : '0',
                whole_words: $('#whole_words').is(':checked') ? '1' : '0'
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
                        self.searchResults = response.data.results;
                        self.displaySearchResults(response.data);
                        self.showMessage('搜索完成', 'success');
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
                    
                    var errorMessage = '搜索请求失败，请重试';
                    
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
                                errorMessage += ' (错误详情: ' + xhr.responseText.substring(0, 100) + '...)';
                            }
                        }
                    }
                    
                    self.showMessage(errorMessage, 'error');
                })
                .always(function() {
                    console.log('WTSR: Search request completed');
                    $button.prop('disabled', false).text(originalText);
                    self.hideProgress();
                });
        },
        
        displaySearchResults: function(data) {
            var self = this;
            
            // Update summary
            $('#wtsr-summary-text').text(data.summary);
            
            // Clear previous results
            $('#wtsr-results-table').empty();
            this.selectedItems = [];
            
            // Combine database and file results
            var allResults = [];
            if (data.results.database) {
                allResults = allResults.concat(data.results.database);
            }
            if (data.results.files) {
                allResults = allResults.concat(data.results.files);
            }
            
            if (allResults.length === 0) {
                $('#wtsr-results-table').html('<p>没有找到匹配的结果</p>');
                $('#wtsr-results').show();
                return;
            }
            
            // Build results HTML
            var resultsHtml = '';
            
            allResults.forEach(function(item, index) {
                resultsHtml += self.buildResultItemHtml(item, index);
            });
            
            $('#wtsr-results-table').html(resultsHtml);
            $('#wtsr-results').show();
            
            // Update replace button state
            this.updateReplaceButtonState();
        },
        
        buildResultItemHtml: function(item, index) {
            var self = this;
            var itemId = 'wtsr-item-' + index;
            var typeLabel = this.getTypeLabel(item.type);
            var statusLabel = this.getStatusLabel(item);
            
            var html = '<div class="wtsr-result-item" data-index="' + index + '">';
            
            // Header
            html += '<div class="wtsr-result-header">';
            html += '<input type="checkbox" class="wtsr-item-checkbox wtsr-checkbox" id="' + itemId + '" data-index="' + index + '">';
            html += '<div class="wtsr-result-content-wrapper">';
            html += '<div class="wtsr-result-title">' + this.escapeHtml(item.title || '无标题') + '</div>';
            html += '<div class="wtsr-result-meta">';
            html += typeLabel + ' | ' + statusLabel + ' | ' + item.match_count + ' 个匹配项';
            if (item.edit_url) {
                html += ' | <a href="' + item.edit_url + '" target="_blank">编辑</a>';
            }
            if (item.view_url) {
                html += ' | <a href="' + item.view_url + '" target="_blank">查看</a>';
            }
            html += '</div>';
            html += '</div>';
            html += '<div class="wtsr-result-actions">';
            html += '<button type="button" class="button wtsr-preview-btn" data-index="' + index + '">预览</button>';
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
            var html = '';
            
            if (item.matches && item.matches.length > 0) {
                item.matches.forEach(function(match) {
                    html += '<div class="wtsr-match-preview">';
                    
                    if (item.type === 'file') {
                        // File match display
                        html += '<strong>行 ' + match.line_number + ':</strong><br>';
                        html += '<div class="wtsr-file-line">';
                        html += '<span class="wtsr-file-line-number">' + match.line_number + '</span>';
                        html += '<span class="wtsr-file-line-content">' + this.escapeHtml(match.line_content) + '</span>';
                        html += '</div>';
                    } else {
                        // Database match display
                        html += '<strong>' + this.getFieldLabel(match.field) + ':</strong><br>';
                        html += this.escapeHtml(match.content.substring(0, 200));
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
                'post': '文章',
                'page': '页面',
                'acf': 'ACF字段',
                'meta': '自定义字段',
                'comment': '评论',
                'file': '文件'
            };
            return labels[type] || type;
        },
        
        getStatusLabel: function(item) {
            if (item.post_status) {
                var statusLabels = {
                    'publish': '已发布',
                    'draft': '草稿',
                    'private': '私有'
                };
                return statusLabels[item.post_status] || item.post_status;
            }
            
            if (item.file_type) {
                var typeLabels = {
                    'theme': '主题文件',
                    'child_theme': '子主题文件',
                    'plugin': '插件文件'
                };
                return typeLabels[item.file_type] || '文件';
            }
            
            return '正常';
        },
        
        getFieldLabel: function(field) {
            var labels = {
                'post_title': '标题',
                'post_content': '内容',
                'post_excerpt': '摘要',
                'meta_value': '字段值',
                'comment_content': '评论内容',
                'comment_author': '评论作者'
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
        
        selectAll: function(e) {
            e.preventDefault();
            
            $('.wtsr-item-checkbox').prop('checked', true);
            this.selectedItems = [];
            
            $('.wtsr-item-checkbox').each(function() {
                var index = parseInt($(this).data('index'));
                this.selectedItems.push(index);
            }.bind(this));
            
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
                $button.text('替换选中项 (' + this.selectedItems.length + ')');
            } else {
                $button.text('替换选中项');
            }
        },
        
        handleReplace: function(e) {
            e.preventDefault();
            
            if (this.selectedItems.length === 0) {
                this.showMessage('请选择要替换的项目', 'warning');
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
            confirmationHtml += '<h4>操作详情：</h4>';
            confirmationHtml += '<ul>';
            confirmationHtml += '<li><strong>搜索文本：</strong>' + this.escapeHtml(searchText) + '</li>';
            confirmationHtml += '<li><strong>替换文本：</strong>' + (replaceText ? this.escapeHtml(replaceText) : '<em>(删除)</em>') + '</li>';
            confirmationHtml += '<li><strong>影响项目：</strong>' + selectedCount + ' 个</li>';
            confirmationHtml += '</ul>';
            confirmationHtml += '</div>';
            
            $('#wtsr-confirmation-details').html(confirmationHtml);
            $('#wtsr-modal').show();
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
            
            // Show loading state
            $button.prop('disabled', true).html('<span class="wtsr-spinner"></span>' + wtsr_ajax.strings.replacing);
            this.showProgress(0, wtsr_ajax.strings.replacing);
            this.clearMessages();
            
            // Prepare selected items data
            var selectedItemsData = [];
            var allResults = [];
            
            if (this.searchResults.database) {
                allResults = allResults.concat(this.searchResults.database);
            }
            if (this.searchResults.files) {
                allResults = allResults.concat(this.searchResults.files);
            }
            
            this.selectedItems.forEach(function(index) {
                if (allResults[index]) {
                    selectedItemsData.push(JSON.stringify(allResults[index]));
                }
            });
            
            var formData = {
                action: 'wtsr_replace',
                nonce: wtsr_ajax.nonce,
                search_text: $('#search_text').val(),
                replace_text: $('#replace_text').val(),
                selected_items: selectedItemsData,
                case_sensitive: $('#case_sensitive').is(':checked') ? '1' : '0',
                regex_mode: $('#regex_mode').is(':checked') ? '1' : '0',
                whole_words: $('#whole_words').is(':checked') ? '1' : '0',
                create_backup: '1' // Always create backup
            };
            
            $.post(wtsr_ajax.ajax_url, formData)
                .done(function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success');
                        
                        // Remove replaced items from results
                        self.removeReplacedItems();
                        
                        // Reset selection
                        self.selectedItems = [];
                        self.updateReplaceButtonState();
                        
                    } else {
                        self.showMessage(response.data.message || '替换失败', 'error');
                    }
                })
                .fail(function() {
                    self.showMessage('替换请求失败，请重试', 'error');
                })
                .always(function() {
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
                    $('#wtsr-results-table').html('<p>所有匹配项已处理完成</p>');
                    $('#wtsr-summary-text').text('没有更多匹配项');
                }
            }, 350);
        },
        
        showPreview: function(e) {
            e.preventDefault();
            var index = parseInt($(e.target).data('index'));
            
            // This would show a detailed preview modal
            // Implementation would depend on specific requirements
            alert('预览功能将在后续版本中实现');
        },
        
        handleBackup: function(e) {
            e.preventDefault();
            
            var self = this;
            var $button = $('#wtsr-backup-btn');
            var originalText = $button.text();
            
            $button.prop('disabled', true).html('<span class="wtsr-spinner"></span>创建备份...');
            
            $.post(wtsr_ajax.ajax_url, {
                action: 'wtsr_backup',
                nonce: wtsr_ajax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    self.showMessage('备份创建成功: ' + response.data.backup_size, 'success');
                } else {
                    self.showMessage(response.data.message || '备份创建失败', 'error');
                }
            })
            .fail(function() {
                self.showMessage('备份请求失败，请重试', 'error');
            })
            .always(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },
        
        closeModal: function(e) {
            if (e && $(e.target).closest('.wtsr-modal-content').length && !$(e.target).hasClass('wtsr-modal-close')) {
                return;
            }
            $('#wtsr-modal').hide();
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
        }
    };
    
    // Initialize the plugin
    WTSR.init();
}); 