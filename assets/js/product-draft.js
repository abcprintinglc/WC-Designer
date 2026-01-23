/* global jQuery, ABCDRAFT */
(function($){
  'use strict';

  function ajaxGet(action, data){
    data = data || {};
    data.action = action;
    data.nonce = ABCDRAFT.nonce;
    return $.get(ABCDRAFT.ajax_url, data);
  }
  function ajaxPost(action, data){
    data = data || {};
    data.action = action;
    data.nonce = ABCDRAFT.nonce;
    return $.post(ABCDRAFT.ajax_url, data);
  }

  function getQty(){
    const $qty = $('form.cart').find('input.qty');
    const v = $qty.length ? parseInt($qty.val() || '1', 10) : 1;
    return isNaN(v) ? 1 : Math.max(1, v);
  }

  function getVariationId(){
    const $v = $('form.cart').find('input.variation_id');
    const v = $v.length ? parseInt($v.val() || '0', 10) : 0;
    return isNaN(v) ? 0 : v;
  }

  function getAttributes(){
    const attrs = {};
    $('form.cart').find('select[name^="attribute_"]').each(function(){
      const name = $(this).attr('name');
      const val = $(this).val();
      if (name && val) attrs[name] = val;
    });
    return attrs;
  }

  function setStatus(msg, isErr){
    const $s = $('#abc_draft_status');
    if(!$s.length) return;
    $s.text(msg || '').toggleClass('abc-error', !!isErr);
  }

    function showPending(p){
    const org = p && p.org_name ? p.org_name : 'your organization';
    const first = p && p.organizer_first ? p.organizer_first : 'the organizer';
    $('#abc_template_notice')
      .show()
      .html('<strong>Approval pending:</strong> ' + escapeHtml(first) + ' must approve your access to ' + escapeHtml(org) + '.');
  }

  $(function(){
    if(!$('#abc-draft-launcher').length) return;

    ajaxGet('abc_b2b_get_templates', { product_id: ABCDRAFT.product_id }).done(function(resp){
      if(!resp || !resp.success){
        setStatus('Unable to load templates.', true);
        $('#abc-draft-launcher').hide();
        return;
      }
      const data = resp.data || {};
      if(data.pending){
        showPending(data.pending);
        // keep launcher hidden until approved
        $('#abc-draft-launcher').hide();
        return;
      }
      const list = data.templates || [];
      if(!list.length){
        // No templates for this product/org — use standard WooCommerce add-to-cart
        $('#abc-draft-launcher').hide();
        return;
      }
      // Templates exist: show Draft workflow launcher (template is chosen on the Draft page)
      $('#abc-draft-launcher').show();
    }).fail(function(){
      setStatus('Unable to load templates.', true);
      $('#abc-draft-launcher').hide();
    });

$('#abc_create_draft').on('click', function(){
      setStatus('Creating draft…');

      ajaxPost('abc_b2b_create_draft', {
        product_id: ABCDRAFT.product_id,
        
        qty: getQty(),
        variation_id: getVariationId(),
        attributes: JSON.stringify(getAttributes())
      }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Could not create draft.', true);
          return;
        }
        const url = resp.data && resp.data.url ? resp.data.url : '';
        if(url){
          window.location.href = url;
          return;
        }
        setStatus('Draft created, but missing editor URL.', true);
      }).fail(function(){
        setStatus('Could not create draft.', true);
      });
    });
  });
})(jQuery);
