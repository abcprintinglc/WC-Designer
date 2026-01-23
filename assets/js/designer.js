/* global jQuery, ABCDES, fabric */
(function($){
  'use strict';

  const state = {
    templates: [],
    template: null,
    surfaceKey: null,
    currentSurfaceCfg: null,
    canvas: null,
    objectsByFieldKey: {},
    lastSavedToken: '',
    isReady: false,
  };

  function inchesToPx(inches, dpi){ return Math.round(inches * dpi); }

  function ensureFontsLoaded(fields){
    try{
      if(!document.fonts || !document.fonts.load) return Promise.resolve();
      const families = Array.from(new Set((fields||[])
        .map(f => (f && (f.font_family || f.fontFamily || f.font || '') || '').toString().trim())
        .filter(Boolean)
      ));
      if(!families.length) return Promise.resolve();
      const loaders = families.map(ff =>
        document.fonts.load('16px "' + ff.replace(/"/g,'\"') + '"').catch(()=>{})
      );
      return Promise.all(loaders).then(()=>document.fonts.ready).catch(()=>{});
    }catch(e){
      return Promise.resolve();
    }
  }


  function setStatus(msg, isError=false){
    const el = $('#abc_save_status');
    el.text(msg);
    el.toggleClass('abc-error', !!isError);
  }

  function ajaxGet(action, data){
    return $.ajax({
      url: ABCDES.ajax_url,
      method: 'GET',
      dataType: 'json',
      data: Object.assign({ action, nonce: ABCDES.nonce }, data || {})
    });
  }

  function ajaxPost(action, data){
    return $.ajax({
      url: ABCDES.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: Object.assign({ action, nonce: ABCDES.nonce }, data || {})
    });
  }

  function buildTemplateSelect(){
    const $sel = $('#abc_template_select');
    $sel.empty();
    if(!state.templates.length){
      // Hide selector if none; show notice instead.
      $sel.closest('.abc-designer-row').addClass('abc-no-templates');
      $sel.hide();
      const notice = $('#abc_template_notice');
      if (notice.length && !notice.is(':visible')) {
        notice.show().text('No brand templates are available for your account yet. Please contact ABC Printing to assign/approve your Organization.');
      }
      return;
    }
    $sel.append($('<option/>').attr('value','').text('Select a template…'));
    state.templates.forEach(t=>{
      $sel.append($('<option/>').attr('value', t.id).text(t.title));
    });
  }

  function renderFieldsForm(surface){
    const $form = $('#abc_fields_form');
    $form.empty();
    const fields = (surface && surface.fields) ? surface.fields : [];
    if(!fields.length){
      $form.append($('<em/>').text('No editable fields on this surface.'));
      return;
    }

    fields.forEach(f=>{
      const id = 'abc_field_' + f.key;
      const $row = $('<div class="abc-field-row"/>');
      const $label = $('<label/>').attr('for', id).text(f.label + (f.required ? ' *' : ''));
      const $input = $('<input/>')
        .attr('id', id)
        .attr('type', 'text')
        .attr('maxlength', f.max_chars || 200)
        .data('fieldKey', f.key)
        .on('input', function(){
          const key = $(this).data('fieldKey');
          const obj = state.objectsByFieldKey[key];
          if(obj){
            obj.text = $(this).val();
            state.canvas.requestRenderAll();
          }
        });
      $row.append($label).append($input);
      $form.append($row);
    });
  }

  function drawGuides(canvas, cfg){
    const trimW = inchesToPx(cfg.trim_w_in, cfg.dpi);
    const trimH = inchesToPx(cfg.trim_h_in, cfg.dpi);
    const bleed = inchesToPx(cfg.bleed_in, cfg.dpi);
    const safe  = inchesToPx(cfg.safe_in, cfg.dpi);

    const trimRect = new fabric.Rect({
      left: bleed, top: bleed, width: trimW, height: trimH,
      fill: 'rgba(0,0,0,0)', stroke: '#00A3FF', strokeWidth: 2,
      selectable: false, evented: false
    });
    const safeRect = new fabric.Rect({
      left: bleed + safe, top: bleed + safe,
      width: Math.max(0, trimW - 2*safe), height: Math.max(0, trimH - 2*safe),
      fill: 'rgba(0,0,0,0)', stroke: '#00CC66', strokeWidth: 2, strokeDashArray: [6,4],
      selectable: false, evented: false
    });

    canvas.add(trimRect);
    canvas.add(safeRect);
    canvas.sendToBack(trimRect);
    canvas.sendToBack(safeRect);
  }

  function loadSurface(surfaceKey){
    if(!state.template) return;
    const surfaces = state.template.surfaces || {};
    const cfg = surfaces[surfaceKey];
    if(!cfg) return;

    state.surfaceKey = surfaceKey;
    state.currentSurfaceCfg = cfg;

    const keys = Object.keys(surfaces);
    const $tabs = $('#abc_surface_tabs');
    $tabs.empty();
    if(keys.length > 1){
      $tabs.show();
      keys.forEach(k=>{
        const $b = $('<button type="button" class="abc-tab"/>').text(k.toUpperCase());
        if(k === surfaceKey) $b.addClass('active');
        $b.on('click', ()=> loadSurface(k));
        $tabs.append($b);
      });
    } else {
      $tabs.hide();
    }

    const dpi = cfg.dpi || 300;
    const totalW = inchesToPx((cfg.trim_w_in + 2*cfg.bleed_in), dpi);
    const totalH = inchesToPx((cfg.trim_h_in + 2*cfg.bleed_in), dpi);

    if(state.canvas){
      state.canvas.dispose();
      state.canvas = null;
    }
    state.canvas = new fabric.Canvas('abc_canvas', {
      preserveObjectStacking: true,
      selection: false
    });
    state.canvas.setWidth(totalW);
    state.canvas.setHeight(totalH);

    state.objectsByFieldKey = {};

    const bgUrl = cfg.bg_url || '';
    if(bgUrl){
      fabric.Image.fromURL(bgUrl, function(img){
        const scaleX = totalW / img.width;
        const scaleY = totalH / img.height;
        img.set({
          left: 0, top: 0,
          selectable: false, evented: false,
          scaleX, scaleY
        });
        state.canvas.setBackgroundImage(img, state.canvas.renderAll.bind(state.canvas));
      }, { crossOrigin: 'anonymous' });
    } else {
      state.canvas.setBackgroundColor('#ffffff', state.canvas.renderAll.bind(state.canvas));
    }

    drawGuides(state.canvas, cfg);

    const fields = cfg.fields || [];
    ensureFontsLoaded(fields).then(()=>{
    fields.forEach(f=>{
      const left = inchesToPx(cfg.bleed_in + (f.left_in || 0), dpi);
      const top  = inchesToPx(cfg.bleed_in + (f.top_in || 0), dpi);
      const w    = inchesToPx((f.width_in || 1), dpi);
      const h    = inchesToPx((f.height_in || 0.25), dpi);

      const tb = new fabric.Textbox('', {
        left, top, width: w,
        fontFamily: f.font_family || 'Arial',
        fontSize: (f.font_size || 16),
        fill: f.color || '#000000',
        textAlign: f.align || 'left',
        fontWeight: f.bold ? '700' : '400',
        fontStyle: f.italic ? 'italic' : 'normal',
        editable: true,
        lockMovementX: true,
        lockMovementY: true,
        lockScalingX: true,
        lockScalingY: true,
        lockRotation: true,
        hasControls: false,
        hasBorders: true,
        borderColor: '#999',
        backgroundColor: 'rgba(255,255,255,0)',
        hoverCursor: 'text'
      });

      tb.on('changed', function(){
        const max = parseInt(f.max_chars || 200, 10);
        if(tb.text && tb.text.length > max){
          tb.text = tb.text.substring(0, max);
        }
      });

      const box = new fabric.Rect({
        left, top, width: w, height: h,
        fill: 'rgba(0,0,0,0)',
        stroke: 'rgba(150,150,150,0.4)',
        strokeWidth: 1,
        selectable: false,
        evented: false
      });

      state.canvas.add(box);
      state.canvas.add(tb);
      state.objectsByFieldKey[f.key] = tb;
    });

    renderFieldsForm(cfg);

    setStatus('Template loaded. Fill fields, then click "Save Proof".');
    state.isReady = true;
    state.canvas.requestRenderAll();
  });
  }

  function onTemplateSelected(templateId){
    state.template = null;
    state.isReady = false;
    $('#abc_template_id').val('');
    $('#abc_design_token').val('');
    state.lastSavedToken = '';
    setStatus('Loading template…');

    ajaxGet('abc_b2b_get_template', { template_id: templateId })
      .done(resp=>{
        if(!resp || !resp.success){
          setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load template.', true);
          return;
        }
        state.template = resp.data.template;
        $('#abc_template_id').val(state.template.id);
        const surfaces = state.template.surfaces || {};
        const first = Object.keys(surfaces)[0] || 'front';
        loadSurface(first);
      })
      .fail(()=>{
        setStatus('Failed to load template.', true);
      });
  }

  function exportSurfacePng(){
    const canvas = state.canvas;
    if(!canvas) return null;

    const guides = [];
    canvas.getObjects().forEach(o=>{
      if(o.type === 'rect' && o.stroke && (o.stroke === '#00A3FF' || o.stroke === '#00CC66' || String(o.stroke).indexOf('rgba(150') === 0)){
        guides.push(o);
      }
    });

    guides.forEach(o=> o.set('opacity', 0));
    canvas.renderAll();
    const dataUrl = canvas.toDataURL({ format: 'png' });
    guides.forEach(o=> o.set('opacity', 1));
    canvas.renderAll();
    return dataUrl;
  }

  function saveDesign(){
    if(!state.template || !state.canvas || !state.isReady){
      setStatus('Select a template first.', true);
      return;
    }

    const payload = {
      template_id: state.template.id,
      product_id: ABCDES.product_id,
      created: new Date().toISOString(),
      surfaces: {}
    };

    const currentSurface = state.surfaceKey;
    payload.surfaces[currentSurface] = { fields: {} };

    Object.keys(state.objectsByFieldKey).forEach(k=>{
      const obj = state.objectsByFieldKey[k];
      payload.surfaces[currentSurface].fields[k] = obj.text || '';
    });

    const previews = {};

    const fields = (state.currentSurfaceCfg && state.currentSurfaceCfg.fields) ? state.currentSurfaceCfg.fields : [];
    setStatus('Preparing fonts…');
    ensureFontsLoaded(fields).then(()=>{
      previews[currentSurface] = exportSurfacePng();
      const svgs = {};
      try { svgs[currentSurface] = state.canvas.toSVG(); } catch(e) { /* ignore */ }

      setStatus('Saving proof…');

      ajaxPost('abc_b2b_save_design', {
      template_id: state.template.id,
      product_id: ABCDES.product_id,
      payload: JSON.stringify(payload),
      previews: previews,
      svgs: svgs
    }).done(resp=>{
      if(!resp || !resp.success){
        setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.', true);
        return;
      }
      const token = resp.data.token;
      state.lastSavedToken = token;
      $('#abc_design_token').val(token);
      setStatus('Saved! You can add to cart now.');
    }).fail(()=>{
      setStatus('Save failed.', true);
    });
    });
  }

  function init(){
    if(!$('#abc-designer').length) return;

    ajaxGet('abc_b2b_get_templates', { product_id: ABCDES.product_id })
      .done(resp=>{
        const data = (resp && resp.data) ? resp.data : {};
        // If server indicates approval is pending, show a friendly notice.
        if (resp && resp.success && data.pending && data.pending.org_name) {
          state.templates = [];
          buildTemplateSelect();
          const notice = $('#abc_template_notice');
          if (notice.length) {
            const who = (data.pending.organizer_first || 'the organizer');
            notice.show().html('<strong>Approval pending</strong><br>Your account is connected to <strong>' + data.pending.org_name +
              '</strong>, but it still needs approval before templates unlock. Please contact <strong>' + who + '</strong> to approve your access.');
          }
          setStatus('Approval pending.', true);
          return;
        }

        state.templates = (resp && resp.success) ? (data.templates || []) : [];
        buildTemplateSelect();
        if (!state.templates.length) {
          setStatus('No templates available.', true);
        } else {
          setStatus('Select a template to begin.');
        }
      })
      .fail(()=>{
        state.templates = [];
        buildTemplateSelect();
        setStatus('Could not load templates.', true);
      });

    $('#abc_template_select').on('change', function(){
      const id = parseInt($(this).val(), 10);
      if(!id){
        setStatus('Select a template to begin.');
        return;
      }
      onTemplateSelected(id);
    });

    $('#abc_save_design').on('click', saveDesign);
  }

  $(init);
})(jQuery);
