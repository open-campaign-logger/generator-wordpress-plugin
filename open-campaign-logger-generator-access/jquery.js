function clGenUpdateResult(generatorId) {
    $that = jQuery('span#cl-gen-id-' + generatorId);
    jQuery.post(cl_gen_ajax.ajax_url, {
        _ajax_nonce: cl_gen_ajax.nonce,
        action: 'cl_gen_ajax_refresh',
        id: generatorId
    }).done(function (data) {
        $that.html(data);
    }).fail(function (error) {
        $that.html(error);
    });
}