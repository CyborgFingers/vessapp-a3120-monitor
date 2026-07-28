$(document).ready(function () {
    // Bind all forms with a submit callback function that prevent users from repeated-submit.
    $('form').bind('submit', function (e) {
        setTimeout(function () {
            $('input[type=submit], button[type=submit]').attr('disabled', 'disabled');
        }, 0);
    });

    $('form').on('input', function() {
        // Bind all input controls in the form that enable submit button on any changed.
        $('input[type=submit], button[type=submit]').removeAttr('disabled');
    });

});
