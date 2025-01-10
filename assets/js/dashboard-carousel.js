(function($) {
    'use strict';

    $(function() {
        console.log('Dashboard carousel script loaded');
        
        // Cache DOM elements
        const $widget = $('#sewn-screenshots-widget');
        const $carousel = $widget.find('.screenshots-carousel');
        const $wrapper = $widget.find('.screenshots-carousel-wrapper');
        const $modal = $('#screenshot-modal');
        let currentScreenshotId = null;
        let currentScreenshotUrl = null;

        // Log initial state
        console.log('Elements found:', {
            widget: $widget.length,
            carousel: $carousel.length,
            wrapper: $wrapper.length,
            modal: $modal.length
        });

        function handleCopyUrl($button) {
            const url = $button.data('url');
            console.log('Copying URL:', url);

            navigator.clipboard.writeText(url)
                .then(() => {
                    console.log('URL copied successfully');
                    $button.find('.sewn-tooltip').text('URL copied!');
                    showNotification('URL copied to clipboard!');
                    
                    setTimeout(() => {
                        $button.find('.sewn-tooltip').text('Copy screenshot URL');
                    }, 2000);
                })
                .catch(err => {
                    console.error('Copy failed:', err);
                    showNotification('Failed to copy URL', 'error');
                });
        }

        function handleViewImage($button) {
            const imageUrl = $button.data('image');
            currentScreenshotId = $button.closest('tr').data('id');
            currentScreenshotUrl = $button.closest('tr').find('.copy-url').data('url');
            
            console.log('Opening modal:', {
                imageUrl: imageUrl,
                id: currentScreenshotId,
                url: currentScreenshotUrl
            });

            $modal.find('.full-screenshot').attr('src', imageUrl);
            $modal.fadeIn(200);
        }

        function handleDeleteImage($button) {
            const id = $button.data('id');
            console.log('Delete requested for ID:', id);

            if ($button.hasClass('confirming')) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sewn_delete_screenshot',
                        screenshot_id: id,
                        nonce: sewnDashboard.nonce
                    },
                    beforeSend: function() {
                        $button.find('.sewn-tooltip').text('Deleting...');
                    },
                    success: function(response) {
                        console.log('Delete response:', response);
                        if (response.success) {
                            const $row = $button.closest('tr');
                            $row.fadeOut(200, function() {
                                $(this).remove();
                                $(`.screenshot-item[data-id="${id}"]`).remove();
                                updateTotalCount();
                            });
                            showNotification('Screenshot deleted successfully!');
                        } else {
                            showNotification(response.data.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Delete failed:', {xhr, status, error});
                        showNotification('Failed to delete screenshot', 'error');
                    }
                });
            } else {
                $button.addClass('confirming');
                $button.find('.sewn-tooltip').text('Click again to confirm');
                setTimeout(() => {
                    $button.removeClass('confirming');
                    $button.find('.sewn-tooltip').text('Delete screenshot');
                }, 3000);
            }
        }

        // Carousel Navigation
        $wrapper.on('click', '.carousel-arrow', function(e) {
            e.preventDefault();
            const $button = $(this);
            console.log('Arrow clicked:', $button.hasClass('next') ? 'next' : 'prev');

            const direction = $button.hasClass('next') ? 1 : -1;
            const scrollAmount = $carousel.width() / 2;
            
            $carousel.animate({
                scrollLeft: $carousel.scrollLeft() + (scrollAmount * direction)
            }, 300);
        });

        // Carousel Item Click
        $carousel.on('click', '.screenshot-item', function(e) {
            e.preventDefault();
            const $item = $(this);
            currentScreenshotId = $item.data('id');
            currentScreenshotUrl = $item.data('url');
            
            console.log('Opening from carousel:', {
                id: currentScreenshotId,
                url: currentScreenshotUrl,
                image: $item.data('image')
            });

            $modal.find('.full-screenshot').attr('src', $item.data('image'));
            $modal.fadeIn(200);
        });

        // Table Actions
        $('.screenshots-table').on('click', '.action-button', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $button = $(this);
            console.log('Action button clicked:', {
                type: $button.attr('class'),
                data: $button.data()
            });

            if ($button.hasClass('copy-url')) {
                handleCopyUrl($button);
            } else if ($button.hasClass('view-screenshot')) {
                handleViewImage($button);
            } else if ($button.hasClass('delete-screenshot')) {
                handleDeleteImage($button);
            }
        });

        // Modal Actions
        $modal.on('click', '.close-modal, .sewn-modal', function(e) {
            if (e.target === this) {
                $modal.fadeOut(200);
            }
        });

        $modal.on('click', '.copy-url', function(e) {
            e.preventDefault();
            handleCopyUrl($(this));
        });

        $modal.on('click', '.delete-screenshot', function(e) {
            e.preventDefault();
            handleDeleteImage($(this));
        });

        function updateTotalCount() {
            const currentTotal = $('.screenshots-table tbody tr').length;
            $('.sewn-card.screenshots-card h2').text(`Recent Screenshots (${currentTotal} Total)`);
        }

        function showNotification(message, type = 'success') {
            console.log('Showing notification:', message);
            const $notification = $('<div>')
                .addClass('sewn-notification ' + type)
                .text(message)
                .appendTo('body');

            $notification.fadeIn(200);
            setTimeout(() => {
                $notification.fadeOut(200, function() {
                    $(this).remove();
                });
            }, 3000);
        }
    });
})(jQuery); 