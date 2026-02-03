jQuery(function($) {
  'use strict';

  // 1) Check if backend passed config
  if (typeof window.ABCDRAFT === 'undefined') {
    console.error('ABCDRAFT config not found. Check class-abc-designer-frontend.php');
    return;
  }

  var CFG = window.ABCDRAFT;

  $('#abc_create_draft').on('click', function(e) {
    e.preventDefault();

    var $btn = $(this);
    var $status = $('#abc_draft_status');
    var productId = $('#abc-draft-launcher').data('product-id') || CFG.product_id || 0;

    // Template selection (new)
    var $tpl = $('#abc_template_select');
    var templateId = 0;
    if ($tpl.length) {
      templateId = parseInt($tpl.val() || 0, 10);
      if (!templateId) {
        alert('Please select a template.');
        return;
      }
    }

    // Variation ID (variable products)
    var variationId = $('input.variation_id').val() || 0;
    var isVariable = $('form.variations_form').length > 0;

    if (isVariable && parseInt(variationId, 10) === 0) {
      alert('Please select your product options (size, paper, etc) before designing.');
      return;
    }

    var qty = $('form.cart input[name="quantity"]').val() || 1;

    // UI feedback
    $btn.prop('disabled', true).text('Creating Draft...');
    $status.text('').css('color', '');

    $.ajax({
      url: CFG.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'abc_b2b_create_draft',
        nonce: CFG.nonce,
        product_id: productId,
        variation_id: variationId,
        qty: qty,
        template_id: templateId
      },
      success: function(response) {
        if (response && response.success) {
          $status.css('color', 'green').text('Draft created! Redirecting...');
          window.location.href = response.data.url;
        } else {
          $btn.prop('disabled', false).text('Create Draft & Customize');
          var msg = (response && response.data && response.data.message) ? response.data.message : 'Error creating draft.';
          $status.css('color', 'red').text(msg);
        }
      },
      error: function(xhr, textStatus, errorThrown) {
        console.error('AJAX Error:', textStatus, errorThrown);
        $btn.prop('disabled', false).text('Create Draft & Customize');
        $status.css('color', 'red').text('Server error. Please check console.');
      }
    });
  });
});
