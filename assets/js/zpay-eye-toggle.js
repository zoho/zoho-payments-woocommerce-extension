jQuery(document).ready(function($) {
    $('.zpay-sensitive-field').each(function() {
        var $input = $(this);
        var $wrapper = $('<div style="position:relative;display:flex;align-items:center;"></div>');
        $input.wrap($wrapper);
        var $eye = $('<span style="margin-left:8px;cursor:pointer;" title="Show/Hide"><span class="dashicons dashicons-visibility"></span></span>');
        $input.after($eye);

        $eye.on('click', function() {
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text'); //No I18N
            } else {
                $input.attr('type', 'password'); //No I18N
            }
        });
    });
});