(function($) {
    'use strict';

    // Refresh stats
    function refreshStats() {
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'sewn_get_stats'
            },
            success: function(response) {
                if (response.success) {
                    updateStatsDisplay(response.data);
                }
            }
        });
    }

    // Initialize
    $(document).ready(function() {
        // Refresh stats every 30 seconds
        setInterval(refreshStats, 30000);

        // Handle API key regeneration
        $('#sewn-regenerate-key').on('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure? This will invalidate the existing API key.')) {
                // Regenerate API key
            }
        });
    });
})(jQuery);