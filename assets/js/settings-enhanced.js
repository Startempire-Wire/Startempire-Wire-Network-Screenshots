(function($) {
    'use strict';

    const SettingsEnhanced = {
        init: function() {
            this.bindEvents();
            console.log('Settings Enhanced initialized');
        },

        bindEvents: function() {
            $('.sewn-settings-form [data-log]').on('change', this.logSettingChange.bind(this));
            $('.sewn-settings-form').on('submit', this.logSettingsSave.bind(this));
        },

        logSettingChange: function(e) {
            const $input = $(e.target);
            const settingName = $input.attr('name');
            const value = $input.is(':checkbox') ? $input.is(':checked') : $input.val();
            
            this.logAction('setting-changed', {
                setting: settingName,
                value: value,
                timestamp: new Date().toISOString()
            });
        },

        logSettingsSave: function(e) {
            const formData = $(e.target).serializeArray();
            
            this.logAction('settings-saved', {
                settings: formData,
                timestamp: new Date().toISOString()
            });
        },

        logAction: function(action, data) {
            const logData = {
                action: action,
                data: data,
                timestamp: new Date().toISOString()
            };
            
            console.log('Settings Log:', logData);
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'sewn_log_settings_action',
                    nonce: sewnSettings.nonce,
                    log_data: logData
                }
            });
        }
    };

    $(document).ready(function() {
        SettingsEnhanced.init();
    });
})(jQuery); 