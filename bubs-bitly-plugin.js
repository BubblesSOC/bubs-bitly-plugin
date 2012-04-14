jQuery(function() {
  var $ = jQuery;
  var bbpButton = $('#bbp_generate_shortlink_button');
  var bbpNonce = $('#bbp_generate_shortlink_nonce');
  var bbpMbox = $('#bitlydiv div.inside');
  bbpButton.bind('click', function(e) {
    e.preventDefault();
    var data = {
      action: 'bbp_generate_shortlink',
      postID: bbpButton.data('postid'),
      bbpNonce: bbpNonce.val()
    };
    $.post(ajaxurl, data, function(response) {
      if (response.status == 'success') {
        bbpMbox.html('<input type="text" size="20" value="' + response.data + '" disabled="disabled" />');
      }
      else {
        alert('Bit.ly shortlink could not be generated. Please try again.');
      }
    });
  });
  var bbpDomainSelect = $('select#bitly_domain');
  var bbpCustomOption = $('#bitly_custom_domain');
  bbpDomainSelect.bind('change', function() {
    if (bbpDomainSelect.find('option:selected').is(bbpCustomOption)) {
      bbpDomainSelect.replaceWith('<span class="description">http://</span> <input name="bbp_settings[bitly_domain]" type="text" id="bitly_domain" />');
    }
  });
});