/* global jQuery, ABCDRAFTED, fabric */
(function($){
  'use strict';

  const state = {
    draft: null,
    template: null,
    surfaceKey: null,
    canvas: null,
    objectsByFieldKey: {},
    currentSurfaceCfg: null,
    payload: null
  };

  function ajaxGet(action, data){
    data = data || {};
    data.action = action;
    data.nonce = ABCDRAFTED.nonce;
    return $.get(ABCDRAFTED.ajax_url, data);
  }
  function ajaxPost(action, data){
    data = data || {};
    data.action = action;
    data.nonce = ABCDRAFTED.nonce;
    return $.post(ABCDRAFTED.ajax_url, data);
  }

  function setStatus(msg, isErr){
    const $s = $('#abc_save_status');
    if(!$s.length) return;
    $s.text(msg || '').toggleClass('abc-error', !!isErr);
  }

  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
  }

  function ensureFontsLoaded(fields){
    if(!document.fonts || !document.fonts.load) return Promise.resolve();
    const fams = Array.from(new Set((fields||[]).map(f=>f.fontFamily).filter(Boolean)));
    return Promise.all(fams.map(fam=>document.fonts.load('16px "'+fam+'"').catch(()=>null))).then(()=>null);
  }

  function surfaceInches(cfg){
    const trimW = Number(cfg.trim_w_in || 3.5);
    const trimH = Number(cfg.trim_h_in || 2.0);
    const bleed = Number(cfg.bleed_in || 0);
    return { w: trimW + 2*bleed, h: trimH + 2*bleed, bleed: bleed };
  }

  function resizeCanvasForSurface(cfg){
    const el = document.getElementById('abc_canvas');
    if(!el || !state.canvas) return;

    const inch = surfaceInches(cfg);
    const maxW = 980; // display width
    const w = maxW;
    const h = Math.round(maxW * (inch.h / inch.w));
    el.width = w;
    el.height = h;
    state.canvas.setWidth(w);
    state.canvas.setHeight(h);
    state.canvas.calcOffset();
  }

  function buildSurfaceTabs(){
    const $tabs = $('#abc_surface_tabs');
    $tabs.empty();
    const keys = state.template ? Object.keys(state.template.surfaces || {}) : [];
    if(keys.length <= 1){
      $tabs.hide();
      return;
    }
    $tabs.show();
    keys.forEach(k=>{
      const btn = $('<button type="button" class="button abc-surface-tab"/>').text((state.template.surfaces[k].label || k));
      btn.on('click', ()=>loadSurface(k));
      $tabs.append(btn);
    });
  }

  function setActiveTab(key){
    $('#abc_surface_tabs .abc-surface-tab').each(function(){
      const label = $(this).text();
      const targetLabel = (state.template.surfaces[key].label || key);
      $(this).toggleClass('is-active', label === targetLabel);
    });
  }

  function loadBackground(cfg){
    return new Promise((resolve)=>{
      const bg = cfg.bg_url || '';
      if(!bg){
        state.canvas.setBackgroundImage(null, state.canvas.renderAll.bind(state.canvas));
        resolve();
        return;
      }
      fabric.Image.fromURL(bg, function(img){
        // scale to fit full canvas
        const cw = state.canvas.getWidth();
        const ch = state.canvas.getHeight();
        const scale = Math.max(cw / img.width, ch / img.height);
        img.set({ originX:'left', originY:'top', left:0, top:0, selectable:false, evented:false, scaleX:scale, scaleY:scale });
        state.canvas.setBackgroundImage(img, state.canvas.renderAll.bind(state.canvas));
        resolve();
      }, { crossOrigin: 'anonymous' });
    });
  }

  function buildObjectsForSurface(cfg){
    state.objectsByFieldKey = {};
    state.currentSurfaceCfg = cfg;

    state.canvas.getObjects().forEach(o=>{ if(o && o._abcFieldKey){ state.canvas.remove(o); }});
    const fields = Array.isArray(cfg.fields) ? cfg.fields : [];

    const inch = surfaceInches(cfg);
    const cw = state.canvas.getWidth();
    const ch = state.canvas.getHeight();
    const pxPerInX = cw / inch.w;
    const pxPerInY = ch / inch.h;

    fields.forEach(f=>{
      const key = String(f.key || '').trim();
      if(!key) return;
      const x = Number(f.x_in || 0) * pxPerInX;
      const y = Number(f.y_in || 0) * pxPerInY;
      const w = Math.max(10, Number(f.w_in || 1.0) * pxPerInX);
      const fontSize = Math.max(8, Number(f.fontSize || 12));
      const fill = f.color || '#111111';
      const align = (f.align || 'left');

      const txt = new fabric.Textbox('', {
        left: x, top: y,
        width: w,
        fontFamily: f.fontFamily || 'Arial',
        fontWeight: f.fontWeight || 'normal',
        fontStyle: f.fontStyle || 'normal',
        fontSize: fontSize,
        fill: fill,
        textAlign: align,
        editable: false,
        selectable: false,
        evented: false
      });
      txt._abcFieldKey = key;
      state.canvas.add(txt);
      state.objectsByFieldKey[key] = txt;
    });

    state.canvas.renderAll();
  }

  function buildFieldsForm(cfg){
    const $form = $('#abc_fields_form');
    $form.empty();
    const fields = Array.isArray(cfg.fields) ? cfg.fields : [];
    if(!fields.length){
      $form.html('<em>No editable fields defined for this surface.</em>');
      return;
    }

    fields.forEach(f=>{
      const key = String(f.key || '').trim();
      const label = f.label || key;
      const val = (state.payload && state.payload.surfaces && state.payload.surfaces[state.surfaceKey] && state.payload.surfaces[state.surfaceKey].fields && state.payload.surfaces[state.surfaceKey].fields[key]) ? state.payload.surfaces[state.surfaceKey].fields[key] : '';
      const $row = $('<div class="abc-field-row"/>');
      $row.append('<label><strong>'+escapeHtml(label)+'</strong></label>');
      const $input = $('<input type="text" class="abc-field-input"/>').attr('data-key', key).val(val);
      $input.on('input', function(){
        const k = $(this).data('key');
        const v = $(this).val();
        if(state.objectsByFieldKey[k]) { state.objectsByFieldKey[k].set('text', v); state.canvas.renderAll(); }
      });
      $row.append($input);
      $form.append($row);
    });
  }

  function applyPayloadToObjects(){
    if(!state.payload || !state.payload.surfaces) return;
    const s = state.payload.surfaces[state.surfaceKey];
    if(!s || !s.fields) return;

    Object.keys(s.fields).forEach(k=>{
      const v = s.fields[k];
      if(state.objectsByFieldKey[k]) {
        state.objectsByFieldKey[k].set('text', v || '');
      }
      $('#abc_fields_form .abc-field-input[data-key="'+k+'"]').val(v || '');
    });
    state.canvas.renderAll();
  }

  function loadSurface(key){
    if(!state.template || !state.template.surfaces || !state.template.surfaces[key]) return;
    state.surfaceKey = key;
    const cfg = state.template.surfaces[key];
    setActiveTab(key);
    resizeCanvasForSurface(cfg);

    setStatus('Loading surface…');
    ensureFontsLoaded(cfg.fields || []).then(()=>{
      return loadBackground(cfg);
    }).then(()=>{
      buildObjectsForSurface(cfg);
      buildFieldsForm(cfg);
      applyPayloadToObjects();
      setStatus('');
    });
  }

  function exportSurfacePng(){
    try{
      return state.canvas.toDataURL({ format:'png', multiplier: 3 });
    }catch(e){
      return null;
    }
  }

  function updatePreviewList(){
    const $p = $('#abc_preview_list');
    $p.empty();
    const previews = (state.draft && state.draft.previews) ? state.draft.previews : {};
    const keys = Object.keys(previews || {});
    if(!keys.length){
      $p.html('<em>No previews saved yet.</em>');
      return;
    }
    keys.forEach(k=>{
      const url = previews[k];
      const $img = $('<img/>').attr('src', url).attr('alt', k).css({ maxWidth:'100%', border:'1px solid #ddd', borderRadius:'8px', marginBottom:'8px' });
      $p.append('<div><strong>'+escapeHtml(k)+'</strong></div>');
      $p.append($img);
    });
  }

  function resetReadyUI(){
    // after any change, ready flags are reset server-side; refresh draft state
    return fetchDraft();
  }

  function fetchDraft(){
    return ajaxGet('abc_b2b_get_draft', { draft_id: ABCDRAFTED.draft_id }).done(function(resp){
      if(!resp || !resp.success){
        setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to load draft.', true);
        return;
      }
      state.draft = resp.data.draft;
      // qty
      $('#abc_draft_qty').val(state.draft.qty || 1);

      // admin controls
      const isOrgAdmin = !!state.draft.is_org_admin || !!state.draft.can_bypass;
      if(isOrgAdmin){
        $('#abc_admin_ready').show();
        $('.abc-override-wrap').show();
      } else {
        $('#abc_admin_ready').hide();
        $('.abc-override-wrap').hide();
      }


  function fetchTemplatesForDraft(){
    if(!state.draft) return $.Deferred().reject().promise();
    return ajaxGet('abc_b2b_get_templates', { product_id: state.draft.product_id }).done(function(resp){
      if(!resp || !resp.success){
        setStatus('Unable to load templates.', true);
        return;
      }
      const data = resp.data || {};
      const list = data.templates || [];
      const $pick = $('#abc_draft_template_picker');
      const $sel = $('#abc_draft_template_select');
      $sel.empty();
      if(!list.length){
        $pick.hide();
        return;
      }
      $sel.append($('<option/>').val('').text('Select a template…'));
      list.forEach(t=>{
        $sel.append($('<option/>').val(t.id).text(t.title));
      });

      if(state.draft.template_id){
        $sel.val(String(state.draft.template_id));
        $('#abc_template_apply').hide();
        $('#abc_template_switch').show();
        $pick.show();
      } else {
        $('#abc_template_apply').show();
        $('#abc_template_switch').hide();
        $pick.show();
      }
    });
  }

  function renderOrderDetails(){
    if(!state.draft) return;
    const $d = $('#abc_order_details');
    if(!$d.length) return;
    const title = state.draft.product_title ? escapeHtml(state.draft.product_title) : ('Product #' + state.draft.product_id);
    const qty = parseInt(state.draft.qty || 1, 10);
    let attrs = '';
    if(state.draft.attributes && typeof state.draft.attributes === 'object'){
      const parts=[];
      Object.keys(state.draft.attributes).forEach(k=>{
        parts.push(escapeHtml(k) + ': ' + escapeHtml(state.draft.attributes[k]));
      });
      if(parts.length) attrs = '<div class="abc-muted" style="margin-top:4px;">' + parts.join('<br>') + '</div>';
    }
    $d.html('<strong>Draft Order Details</strong><div style="margin-top:4px;">' + title + '</div><div class="abc-muted">Qty: ' + qty + '</div>' + attrs);
  }

      $('#abc_ready_override').prop('checked', !!state.draft.ready_override);

      // Add to cart visibility
      const fullyApproved = (state.draft.employee_ready && state.draft.admin_ready) || state.draft.ready_override;
      if(isOrgAdmin && fullyApproved){
        $('#abc_add_draft_to_cart').show();
      } else {
        $('#abc_add_draft_to_cart').hide();
      }

      // Button states
      $('#abc_employee_ready').toggleClass('button-primary', !!state.draft.employee_ready);
      $('#abc_admin_ready').toggleClass('button-primary', !!state.draft.admin_ready);

      // previews
      updatePreviewList();
      if(state.draft && state.draft.payload){
        state.payload = state.draft.payload;
      } else if(!state.payload){
        state.payload = { template_id: state.draft.template_id, product_id: state.draft.product_id, created: new Date().toISOString(), surfaces: {} };
      }

      // view cart link
      if(state.draft.cart_url){
        $('#abc_view_cart').attr('href', state.draft.cart_url);
      }
    });
  }

  function fetchTemplate(){
    const tid = state.draft.template_id;
    return ajaxGet('abc_b2b_get_template', { template_id: tid }).done(function(resp){
      if(!resp || !resp.success){
        setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to load template.', true);
        return;
      }
      state.template = resp.data.template;
      buildSurfaceTabs();

      // init canvas if needed
      if(!state.canvas){
        state.canvas = new fabric.Canvas('abc_canvas', { selection: false, preserveObjectStacking: true });
      }

      const keys = Object.keys(state.template.surfaces || {});
      const first = keys.length ? keys[0] : null;
      if(!first){
        setStatus('Template has no surfaces.', true);
        return;
      }
      loadSurface(first);
    });
  }

  function saveDraft(){
    if(!state.template || !state.canvas){
      setStatus('Template not ready.', true);
      return;
    }

    // Merge surface fields into payload
    const skey = state.surfaceKey || 'front';
    if(!state.payload.surfaces) state.payload.surfaces = {};
    if(!state.payload.surfaces[skey]) state.payload.surfaces[skey] = { fields: {} };
    const fields = state.payload.surfaces[skey].fields;

    Object.keys(state.objectsByFieldKey).forEach(k=>{
      const obj = state.objectsByFieldKey[k];
      fields[k] = obj && obj.text ? obj.text : '';
    });

    const previews = {};
    const svgs = {};
    setStatus('Preparing fonts…');
    ensureFontsLoaded(state.currentSurfaceCfg ? state.currentSurfaceCfg.fields : []).then(()=>{
      previews[skey] = exportSurfacePng();
      try { svgs[skey] = state.canvas.toSVG(); } catch(e) {}

      setStatus('Saving proof…');
      return ajaxPost('abc_b2b_save_draft', {
        draft_id: ABCDRAFTED.draft_id,
        payload: JSON.stringify(state.payload),
        previews: previews,
        svgs: svgs
      });
    }).done(function(resp){
      if(!resp || !resp.success){
        setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.', true);
        return;
      }
      setStatus('Saved. Approvals reset (mark Ready again).');
      // refresh draft
      fetchDraft();
    }).fail(function(){
      setStatus('Save failed.', true);
    });
  }

  function wireUI(){
    // Save
    $('#abc_save_draft').on('click', saveDraft);

    // Qty
    let qtyTimer = null;
    $('#abc_draft_qty').on('input', function(){
      const v = parseInt($(this).val() || '1', 10);
      const qty = isNaN(v) ? 1 : Math.max(1, v);
      clearTimeout(qtyTimer);
      qtyTimer = setTimeout(function(){
        setStatus('Saving quantity…');
        ajaxPost('abc_b2b_update_draft_qty', { draft_id: ABCDRAFTED.draft_id, qty: qty }).done(function(resp){
          if(!resp || !resp.success){
            setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Qty save failed.', true);
            return;
    // Template apply/switch (template-first flow)
    $('#abc_template_apply').on('click', function(){
      const tid = parseInt($('#abc_draft_template_select').val() || '0', 10);
      if(!tid){
        setStatus('Choose a template first.', true);
        return;
      }
      setStatus('Applying template…');
      ajaxPost('abc_b2b_set_draft_template', { draft_id: ABCDRAFTED.draft_id, template_id: tid }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to apply template.', true);
          return;
        }
        // reload draft/template
        fetchDraft().done(function(r){
          if(r && r.success){
            $('#abc_template_apply').hide();
            $('#abc_template_switch').show();
            fetchTemplate();
          }
        });
      });
    });

    $('#abc_template_switch').on('click', function(){
      const tid = parseInt($('#abc_draft_template_select').val() || '0', 10);
      if(!tid){
        setStatus('Choose a template first.', true);
        return;
      }
      if(!confirm('Switch template? This will reset approvals and clear saved previews.')) return;
      setStatus('Switching template…');
      ajaxPost('abc_b2b_set_draft_template', { draft_id: ABCDRAFTED.draft_id, template_id: tid }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to switch template.', true);
          return;
        }
        // Clear UI
        $('#abc_preview_list').html('<em>No previews saved yet.</em>');
        state.canvas && state.canvas.clear();
        state.objectsByFieldKey = {};
        fetchDraft().done(function(r){
          if(r && r.success){
            fetchTemplate();
          }
        });
      });
    });
  }
          setStatus('Quantity saved. Approvals reset.');
          fetchDraft();
        }).fail(function(){
          setStatus('Qty save failed.', true);
        });
      }, 400);
    });

    // Ready buttons
    $('#abc_employee_ready').on('click', function(){
      setStatus('Marking employee ready…');
      ajaxPost('abc_b2b_set_employee_ready', { draft_id: ABCDRAFTED.draft_id }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to mark ready.', true);
          return;
        }
        setStatus('Employee Ready set.');
        fetchDraft();
      }).fail(()=>setStatus('Unable to mark ready.', true));
    });

    $('#abc_admin_ready').on('click', function(){
      setStatus('Marking org admin ready…');
      ajaxPost('abc_b2b_set_admin_ready', { draft_id: ABCDRAFTED.draft_id }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to approve.', true);
          return;
        }
        setStatus('Org Admin Ready set.');
        fetchDraft();
      }).fail(()=>setStatus('Unable to approve.', true));
    });

    $('#abc_ready_override').on('change', function(){
      const val = $(this).is(':checked');
      setStatus('Updating override…');
      ajaxPost('abc_b2b_set_ready_override', { draft_id: ABCDRAFTED.draft_id, override: val ? 1 : 0 }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Override update failed.', true);
          return;
        }
        setStatus('Override updated.');
        fetchDraft();
      }).fail(()=>setStatus('Override update failed.', true));
    });

    $('#abc_add_draft_to_cart').on('click', function(){
      setStatus('Adding to cart…');
      ajaxPost('abc_b2b_add_draft_to_cart', { draft_id: ABCDRAFTED.draft_id }).done(function(resp){
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Add to cart failed.', true);
          return;
        }
        setStatus('Added to cart.');
        const cartUrl = (resp.data && resp.data.cart_url) ? resp.data.cart_url : (state.draft && state.draft.cart_url ? state.draft.cart_url : '#');
        if(cartUrl){
          $('#abc_view_cart').attr('href', cartUrl).show();
        }
      }).fail(()=>setStatus('Add to cart failed.', true));
    });
  }

  $(function(){
    if(!$('#abc-draft-editor').length) return;
    if(!ABCDRAFTED || !ABCDRAFTED.draft_id){
      setStatus('Missing draft id.', true);
      return;
    }

    wireUI();

    fetchDraft().done(function(resp){
      if(resp && resp.success){
        renderOrderDetails();
        fetchTemplatesForDraft().done(function(){
          if(state.draft && state.draft.template_id){
            fetchTemplate();
          } else {
            setStatus('Choose a template to start.', true);
          }
        });
      }
    });
  });

})(jQuery);
