jQuery(document).ready(function($) {
    var $dataCenter = $('#woocommerce_zpay_data_center');
    var $sandbox = $('#woocommerce_zpay_sandbox_enabled');
    var $sandboxRow = $sandbox.closest('tr');

    function toggleSandboxField() {
        if ($dataCenter.val() === 'in') {
            $sandboxRow.show();
            return;
        }

        $sandbox.prop('checked', false);
        $sandboxRow.hide();
    }

    $dataCenter.on('change', toggleSandboxField);
    toggleSandboxField();

    $('#zpay-generate-token').on('click', function() {
        var client_id = $('#woocommerce_zpay_client_id').val();
        var client_secret = $('#woocommerce_zpay_client_secret').val();
        var auth_code = $('#woocommerce_zpay_auth_code').val();
        var data_center = $('#woocommerce_zpay_data_center').val();

        $('#zpay-token-status').text('Generating...'); //No I18N

        $.ajax({
            url: ajaxurl,
            method: 'POST', //No I18N
            data: {
                action: 'zpay_generate_token', //No I18N
                client_id: client_id,
                client_secret: client_secret,
                auth_code: auth_code,
                data_center: data_center,
                _wpnonce: zpay_admin_nonce.value
            },
            success: function(response) {
                if (response.success) {
                    $('#zpay-token-status').text('Token generated and saved!'); //No I18N
                } else {
                    $('#zpay-token-status').text('Error: ' + response.data); //No I18N
                }
            }
        });
    });
});