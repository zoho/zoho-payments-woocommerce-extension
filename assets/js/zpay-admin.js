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
});