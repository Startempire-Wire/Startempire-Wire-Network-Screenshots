<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div id="test-log" class="sewn-test-log">
        <!-- Test results will be displayed here -->
    </div>

    <button id="sewn-run-all-tests" class="button" 
        data-nonce="<?php echo wp_create_nonce('sewn_run_all_tests'); ?>">
        <?php _e('Run All Tests', 'startempire-wire-network-screenshots'); ?>
    </button>
</div> 