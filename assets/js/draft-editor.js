/* global jQuery, ABCDRAFTED, fabric */
jQuery(function ($) {
  'use strict';

  // -----------------------------
  // Guardrails
  // -----------------------------
  if (typeof window.ABCDRAFTED === 'undefined') {
    console.error('[ABC Draft Editor] ABCDRAFTED is undefined. Check wp_localize_script.');
    return;
  }
  if (typeof window.fabric === 'undefined') {
    console.error('[ABC Draft Editor] Fabric.js not loaded.');
    return;
  }

  var CFG = window.ABCDRAFTED;
  if (!CFG.ajax_url || !CFG.nonce || !CFG.draft_id) {
    console.error('[ABC Draft Editor] Missing config keys (ajax_url, nonce, draft_id).', CFG);
    return;
  }

  // Ensure custom properties survive save/load
  (function extendFabricSerialization() {
    try {
      var orig = fabric.Object.prototype.toObject;
      fabric.Object.prototype.toObject = function (props) {
        var o = orig.call(this, props);
        o.abcFieldKey = this.abcFieldKey || null;
        o.abcFieldAlign = this.abcFieldAlign || null;
        o.abcFieldFontFamily = this.abcFieldFontFamily || null;
        o.abcFieldFontSize = this.abcFieldFontSize || null;
        o.abcFieldFill = this.abcFieldFill || null;
        return o;
      };
    } catch (e) {
      console.warn('[ABC Draft Editor] Could not extend fabric serialization', e);
    }
  })();

  // -----------------------------
  // DOM
  // -----------------------------
  var $qty = $('#abc_draft_qty');
  var $orderDetails = $('#abc_order_details');
  var $fieldsWrap = $('#abc_fields_form');
  var $saveBtn = $('#abc_save_draft');
  var $employeeBtn = $('#abc_employee_ready');
  var $adminBtn = $('#abc_admin_ready');
  var $addToCartBtn = $('#abc_add_draft_to_cart');
  var $tplSelect = $('#abc_draft_template'); // if present

  // -----------------------------
  // Canvas
  // -----------------------------
  var canvas = new fabric.Canvas('abc_canvas', {
    selection: false,
    preserveObjectStacking: true
  });

  var fieldObjectMap = {}; // key -> fabric.Textbox
  var currentTemplate = null;
  var currentSurface = null;

  var isAdmin = false;
  var canBypass = false;

  // Default to 96 px per inch unless template specifies
  var PX_PER_IN = 96;

  // -----------------------------
  // Helpers
  // -----------------------------
  function esc(s) {
    if (s === null || typeof s === 'undefined') return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setLoading(msg) {
    $fieldsWrap.html('<em>' + esc(msg || 'Loading…') + '</em>');
  }

  function post(action, data) {
    data = data || {};
    data.action = action;
    data.nonce = CFG.nonce;
    return $.ajax({
      url: CFG.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: data
    });
  }

  function get(action, data) {
    data = data || {};
    data.action = action;
    data.nonce = CFG.nonce;
    return $.ajax({
      url: CFG.ajax_url,
      type: 'GET',
      dataType: 'json',
      data: data
    });
  }

  function toNum(v, def) {
    var n = parseFloat(v);
    return (isNaN(n) ? def : n);
  }

  function lockAllObjects() {
    // Users should not drag/move objects; only admins/bypass can.
    var lock = !(isAdmin || canBypass);
    canvas.selection = !lock;

    canvas.forEachObject(function (obj) {
      if (!obj) return;

      if (obj.abcIsBackground) {
        obj.selectable = false;
        obj.evented = false;
        return;
      }

      obj.selectable = !lock;
      obj.evented = !lock;

      obj.lockMovementX = lock;
      obj.lockMovementY = lock;
      obj.lockScalingX = lock;
      obj.lockScalingY = lock;
      obj.lockRotation = lock;

      obj.hasControls = !lock;
      obj.hasBorders = !lock;
    });

    canvas.requestRenderAll();
  }

  function pickVariationValue(draft, needleRegex) {
    try {
      if (!draft) return '';
      // Prefer structured attributes
      var attrs = draft.variation_attributes;
      if (Array.isArray(attrs)) {
        for (var i = 0; i < attrs.length; i++) {
          var a = attrs[i] || {};
          var label = (a.label || a.name || '').toString();
          var name = (a.name || '').toString();
          if (needleRegex.test(label.toLowerCase()) || needleRegex.test(name.toLowerCase())) {
            return (a.value || '').toString();
          }
        }
      }
      // Fallback: parse variation_string
      var s = (draft.variation_string || '').toString();
      // best-effort: split by commas and look for "Label: Value"
      var parts = s.split(',');
      for (var j = 0; j < parts.length; j++) {
        var p = parts[j].trim();
        var pLower = p.toLowerCase();
        if (needleRegex.test(pLower)) {
          var idx = p.indexOf(':');
          if (idx >= 0) return p.slice(idx + 1).trim();
          return p.trim();
        }
      }
      return '';
    } catch (e) {
      return '';
    }
  }

  function buildOptionsSummary(draft) {
    // Build a clean, human readable options line in a consistent order
    var chunks = [];
    var used = {};

    function add(label, value) {
      if (!value) return;
      var key = label.toLowerCase();
      if (used[key]) return;
      used[key] = true;
      chunks.push(label + ': ' + value);
    }

    // Preferred fields
    add('Stock', pickVariationValue(draft, /stock|paper/));
    add('Ink', pickVariationValue(draft, /ink/));
    add('Sides', pickVariationValue(draft, /side/));

    // Add any remaining attributes not already included
    if (Array.isArray(draft.variation_attributes)) {
      for (var i = 0; i < draft.variation_attributes.length; i++) {
        var a = draft.variation_attributes[i] || {};
        var label = (a.label || a.name || '').toString();
        var value = (a.value || '').toString();
        var lower = label.toLowerCase();
        if (!label || !value) continue;
        if (/(stock|paper|ink|side)/.test(lower)) continue;
        add(label, value);
      }
    } else if (draft.variation_string) {
      // last fallback
      chunks = [draft.variation_string];
    }

    return chunks.join(', ');
  }

  function renderInlineOptions(draft) {
    // Shows Ink + Sides next to Qty (top-left), per your markup.
    try {
      if (!$qty.length) return;
      var $wrap = $('#abc_inline_options');
      if (!$wrap.length) {
        $wrap = $('<div id="abc_inline_options" style="display:inline-flex;gap:14px;align-items:center;margin-left:16px;vertical-align:middle;"></div>');
        // insert after qty input
        $qty.after($wrap);
      }

      var ink = pickVariationValue(draft, /ink/);
      var sides = pickVariationValue(draft, /side/);

      var html = '';
      if (ink) {
        html += '<span class="abc-inline-opt"><strong>Ink:</strong> ' + esc(ink) + '</span>';
      }
      if (sides) {
        html += '<span class="abc-inline-opt"><strong>Sides:</strong> ' + esc(sides) + '</span>';
      }
      if (!html) {
        html = '<span class="abc-inline-opt"><em>Options:</em> ' + esc(draft.variation_string || '') + '</span>';
      }

      $wrap.html(html);
    } catch (e) {
      console.warn('[ABC Draft Editor] renderInlineOptions failed', e);
    }
  }

  function setOrderHeader(draft) {
    try {
      var title = draft.product_title ? esc(draft.product_title) : '';
      var options = buildOptionsSummary(draft);
      var html = '';
      if (title) html += '<div><strong>Product:</strong> ' + title + '</div>';
      if (options) html += '<div><strong>Options:</strong> ' + esc(options) + '</div>';

      // show qty in the summary box as well
      var q = parseInt(draft.qty, 10);
      if (!q || q < 1) q = 1;
      html += '<div><strong>Amount:</strong> ' + esc(q) + '</div>';

      $orderDetails.html(html || '');

      // Also render the inline (left-side) quick options
      renderInlineOptions(draft);
    } catch (e) {
      console.warn('[ABC Draft Editor] Could not set header', e);
    }
  }

  function setQtyInput(qtyVal) {
    if (!$qty.length) return;
    var q = parseInt(qtyVal, 10);
    if (!q || q < 1) q = 1;
    $qty.val(q);
  }

  function bindQtyChange() {
    if (!$qty.length) return;
    $qty.off('change.abc').on('change.abc', function () {
      var q = parseInt($qty.val(), 10);
      if (!q || q < 1) q = 1;
      $qty.val(q);

      // changing qty resets approvals server-side
      post('abc_b2b_update_draft_qty', {
        draft_id: CFG.draft_id,
        qty: q
      }).done(function (res) {
        if (!res || !res.success) {
          console.warn('[ABC Draft Editor] Qty update failed', res);
        } else {
          // reflect on-screen; approvals reset happens server-side
          updateApprovalUI({ employee_ready: 0, admin_ready: 0, ready_override: 0, is_org_admin: isAdmin, can_bypass: canBypass });
        }
      }).fail(function (xhr) {
        console.error('[ABC Draft Editor] Qty update AJAX failed', xhr);
      });
    });
  }

  function updateApprovalUI(draft) {
    // draft contains: employee_ready, admin_ready, ready_override, is_org_admin, can_bypass
    try {
      if (draft.employee_ready) {
        $employeeBtn.text('Employee Ready ✓').prop('disabled', true);
      } else {
        $employeeBtn.text('Employee Ready').prop('disabled', false);
      }

      // Admin button visibility
      if (draft.is_org_admin || draft.can_bypass) {
        $adminBtn.show();
        if (draft.admin_ready) {
          $adminBtn.text('Org Admin Ready ✓').prop('disabled', true);
        } else {
          $adminBtn.text('Org Admin Ready').prop('disabled', false);
        }
      } else {
        $adminBtn.hide();
      }

      // Add to cart visibility (only when fully approved OR override)
      var approved = (!!draft.ready_override) || (!!draft.employee_ready && !!draft.admin_ready);
      if (approved && (draft.is_org_admin || draft.can_bypass)) {
        $addToCartBtn.show();
      } else {
        $addToCartBtn.hide();
      }
    } catch (e) {
      console.warn('[ABC Draft Editor] updateApprovalUI error', e);
    }
  }

  function pickFirstSurface(template) {
    if (!template || !template.surfaces) return null;
    if (Array.isArray(template.surfaces) && template.surfaces.length) return template.surfaces[0];
    // sometimes surfaces saved as object map
    if (typeof template.surfaces === 'object') {
      var keys = Object.keys(template.surfaces);
      if (keys.length) return template.surfaces[keys[0]];
    }
    return null;
  }

  function normalizeFields(surface) {
    if (!surface) return [];
    var fields = surface.fields || surface.text_fields || surface.textFields || [];
    if (!Array.isArray(fields)) return [];
    return fields;
  }

  function bgUrlFromSurface(surface) {
    if (!surface) return '';
    return surface.bg_url || surface.bgUrl || surface.background_url || surface.backgroundUrl || '';
  }

  function setCanvasSizeFromSurface(surface) {
    // Use surface inches + DPI if available, otherwise use existing canvas size
    var wIn = toNum(surface.width_in || surface.widthIn, 0);
    var hIn = toNum(surface.height_in || surface.heightIn, 0);
    var dpi = toNum(surface.dpi || surface.px_per_in || surface.pxPerIn, 0);
    if (dpi > 0) PX_PER_IN = dpi;

    var wPx = (wIn > 0) ? Math.round(wIn * PX_PER_IN) : canvas.getWidth();
    var hPx = (hIn > 0) ? Math.round(hIn * PX_PER_IN) : canvas.getHeight();

    // Sanity clamp
    if (wPx < 200) wPx = 900;
    if (hPx < 200) hPx = 600;

    canvas.setWidth(wPx);
    canvas.setHeight(hPx);
  }

  // -----------------------------
  // Template Rendering (wrapped in try/catch)
  // -----------------------------
  function renderTemplateOnCanvas(template) {
    // IMPORTANT: wrap everything so one bad field/font doesn't hang the entire page.
    try {
      currentTemplate = template;
      currentSurface = pickFirstSurface(template);
      if (!currentSurface) {
        console.warn('[ABC Draft Editor] No surface found in template', template);
        return;
      }

      // reset
      fieldObjectMap = {};
      canvas.clear();

      setCanvasSizeFromSurface(currentSurface);

      // Background
      var bg = bgUrlFromSurface(currentSurface);
      if (bg) {
        fabric.Image.fromURL(bg, function (img) {
          try {
            img.set({
              selectable: false,
              evented: false
            });
            img.abcIsBackground = true;

            // Fit bg to canvas
            var sx = canvas.getWidth() / img.width;
            var sy = canvas.getHeight() / img.height;
            img.scaleX = sx;
            img.scaleY = sy;

            canvas.setBackgroundImage(img, canvas.requestRenderAll.bind(canvas), { crossOrigin: 'anonymous' });
          } catch (e2) {
            console.error('[ABC Draft Editor] Background load error', e2);
          }
        }, { crossOrigin: 'anonymous' });
      } else {
        canvas.setBackgroundColor('#ffffff', canvas.requestRenderAll.bind(canvas));
      }

      // Text fields
      var fields = normalizeFields(currentSurface);

      for (var i = 0; i < fields.length; i++) {
        try {
          var field = fields[i] || {};
          var key = field.key || field.field_key || field.id || ('field_' + i);

          var leftIn = toNum(field.left_in, 0);
          var topIn = toNum(field.top_in, 0);
          var widthIn = toNum(field.width_in, 0);
          var heightIn = toNum(field.height_in, 0);

          var align = (field.align || 'left').toString().toLowerCase();
          var fontFamily = field.font_family || 'Arial';
          var fontSize = toNum(field.font_size, 24);
          var fill = field.color || '#000000';

          var leftPx = leftIn * PX_PER_IN;
          var topPx = topIn * PX_PER_IN;
          var widthPx = (widthIn > 0 ? widthIn * PX_PER_IN : 300);
          var heightPx = (heightIn > 0 ? heightIn * PX_PER_IN : 60);

          var originX = 'left';
          if (align === 'center') {
            originX = 'center';
            leftPx = (field.left_in * PX_PER_IN) + ((field.width_in * PX_PER_IN) / 2);
          } else if (align === 'right') {
            originX = 'right';
            leftPx = (field.left_in * PX_PER_IN) + (field.width_in * PX_PER_IN);
          }

          var textbox = new fabric.Textbox('', {
            left: leftPx,
            top: topPx,
            width: widthPx,
            height: heightPx,

            originX: originX,
            originY: 'top',

            fontSize: fontSize,
            fontFamily: fontFamily,
            fill: fill,
            textAlign: align,

            selectable: false,
            evented: false
          });

          textbox.abcFieldKey = key;
          textbox.abcFieldAlign = align;
          textbox.abcFieldFontFamily = fontFamily;
          textbox.abcFieldFontSize = fontSize;
          textbox.abcFieldFill = fill;

          fieldObjectMap[key] = textbox;
          canvas.add(textbox);
        } catch (fieldErr) {
          console.error('[ABC Draft Editor] Field render error', fieldErr, fields[i]);
        }
      }

      lockAllObjects();
      canvas.requestRenderAll();
      renderFormFields(normalizeFields(currentSurface));
    } catch (err) {
      console.error('[ABC Draft Editor] renderTemplateOnCanvas error', err);
      // Keep UI usable even if the canvas fails
      try { renderFormFields([]); } catch (e3) {}
    }
  }

  // -----------------------------
  // Sidebar Fields + Binding
  // -----------------------------
  function renderFormFields(fields) {
    try {
      if (!Array.isArray(fields) || !fields.length) {
        $fieldsWrap.html('<em>No fields found for this template.</em>');
        return;
      }

      var html = '';
      for (var i = 0; i < fields.length; i++) {
        var f = fields[i] || {};
        var key = f.key || f.field_key || f.id || ('field_' + i);
        var label = f.label || f.name || key;

        html += ''
          + '<div class="abc-field-row" style="margin:0 0 14px 0;">'
          + '  <label style="display:block;font-weight:600;margin:0 0 6px 0;">' + esc(label) + '</label>'
          + '  <input type="text" class="abc-field-input" data-field-key="' + esc(key) + '" style="width:100%;" />'
          + '</div>';
      }

      $fieldsWrap.html(html);

      // Bind typing -> canvas text
      $fieldsWrap.find('.abc-field-input').off('input.abc keyup.abc').on('input.abc keyup.abc', function () {
        var $inp = $(this);
        var key = $inp.data('field-key');
        var val = $inp.val();

        var obj = fieldObjectMap[key];
        if (!obj) {
          // fallback: match by order if keys didn't serialize in old payloads
          obj = findTextboxByIndex(key, fields);
        }
        if (!obj) return;

        obj.set('text', val);

        // Ensure style stays consistent if we add UI controls later
        if (obj.abcFieldFontSize) obj.set('fontSize', obj.abcFieldFontSize);
        if (obj.abcFieldFontFamily) obj.set('fontFamily', obj.abcFieldFontFamily);
        if (obj.abcFieldFill) obj.set('fill', obj.abcFieldFill);
        if (obj.abcFieldAlign) {
          obj.set('textAlign', obj.abcFieldAlign);
          if (obj.abcFieldAlign === 'center') {
            obj.set('originX', 'center');
          } else if (obj.abcFieldAlign === 'right') {
            obj.set('originX', 'right');
          } else {
            obj.set('originX', 'left');
          }
        }

        canvas.requestRenderAll();
      });
    } catch (e) {
      console.error('[ABC Draft Editor] renderFormFields error', e);
      $fieldsWrap.html('<em>Error rendering fields. Check console.</em>');
    }
  }

  function findTextboxByIndex(key, fields) {
    // If old payloads didn't serialize abcFieldKey, we can fallback by matching
    // the Nth textbox object to the Nth field.
    var idx = -1;
    for (var i = 0; i < fields.length; i++) {
      var k = fields[i].key || fields[i].field_key || fields[i].id || ('field_' + i);
      if (k === key) { idx = i; break; }
    }
    if (idx < 0) return null;

    var tbs = [];
    canvas.forEachObject(function (o) {
      if (o && (o.type === 'textbox' || o.type === 'text')) tbs.push(o);
    });
    return tbs[idx] || null;
  }

  // -----------------------------
  // Draft Loading
  // -----------------------------
  function loadDraft() {
    setLoading('Loading…');

    get('abc_b2b_get_draft', { draft_id: CFG.draft_id })
      .done(function (res) {
        if (!res || !res.success || !res.data || !res.data.draft) {
          console.error('[ABC Draft Editor] get_draft failed', res);
          setLoading('Unable to load draft. Check console.');
          return;
        }

        var draft = res.data.draft;

        isAdmin = !!draft.is_org_admin;
        canBypass = !!draft.can_bypass;

        setQtyInput(draft.qty);
        bindQtyChange();
        setOrderHeader(draft);

        updateApprovalUI(draft);

        // Prefer payload if exists
        if (draft.payload) {
          try {
            canvas.clear();

            // fabric can load from object or JSON string; we normalize to string
            var payloadStr = (typeof draft.payload === 'string') ? draft.payload : JSON.stringify(draft.payload);

            canvas.loadFromJSON(payloadStr, function () {
              try {
                // Rebuild field map from loaded objects
                fieldObjectMap = {};
                canvas.forEachObject(function (o) {
                  if (o && o.abcFieldKey) fieldObjectMap[o.abcFieldKey] = o;
                });

                // If no background got serialized, we still fetch template to set it
                fetchTemplateAndFinalize(draft.template_id, true);
              } catch (e) {
                console.error('[ABC Draft Editor] Post payload load error', e);
                fetchTemplateAndFinalize(draft.template_id, true);
              }
            });
          } catch (e2) {
            console.error('[ABC Draft Editor] loadFromJSON failed, falling back to template', e2);
            fetchTemplateAndFinalize(draft.template_id, false);
          }
        } else {
          fetchTemplateAndFinalize(draft.template_id, false);
        }
      })
      .fail(function (xhr) {
        console.error('[ABC Draft Editor] get_draft AJAX failed', xhr);
        setLoading('Server error loading draft. Check console.');
      });
  }

  function fetchTemplateAndFinalize(templateId, preserveCanvasObjects) {
    if (!templateId) {
      setLoading('Missing template.');
      return;
    }

    get('abc_b2b_get_template', { template_id: templateId })
      .done(function (res) {
        if (!res || !res.success || !res.data || !res.data.template) {
          console.error('[ABC Draft Editor] get_template failed', res);
          setLoading('Unable to load template. Check console.');
          return;
        }

        var tpl = res.data.template;

        if (preserveCanvasObjects) {
          // We already loaded objects from payload. We still need sidebar fields and possibly bg.
          currentTemplate = tpl;
          currentSurface = pickFirstSurface(tpl);
          if (currentSurface) {
            var bg = bgUrlFromSurface(currentSurface);
            // If there is no background on canvas, set it
            if (bg && !canvas.backgroundImage) {
              setCanvasSizeFromSurface(currentSurface);
              fabric.Image.fromURL(bg, function (img) {
                try {
                  img.set({ selectable: false, evented: false });
                  img.abcIsBackground = true;
                  img.scaleX = canvas.getWidth() / img.width;
                  img.scaleY = canvas.getHeight() / img.height;
                  canvas.setBackgroundImage(img, canvas.requestRenderAll.bind(canvas), { crossOrigin: 'anonymous' });
                } catch (e) {
                  console.error('[ABC Draft Editor] BG set error', e);
                }
              }, { crossOrigin: 'anonymous' });
            }
            renderFormFields(normalizeFields(currentSurface));
            lockAllObjects();
          } else {
            renderFormFields([]);
          }
        } else {
          renderTemplateOnCanvas(tpl);
        }

        setLoading(''); // clear "Loading..."
        $fieldsWrap.find('em').remove();
      })
      .fail(function (xhr) {
        console.error('[ABC Draft Editor] get_template AJAX failed', xhr);
        setLoading('Server error loading template. Check console.');
      });
  }

  // -----------------------------
  // Save / Approve / Cart
  // -----------------------------
  function saveDraft() {
    if ($saveBtn.prop('disabled')) return;

    $saveBtn.prop('disabled', true).text('Saving…');

    var payload = JSON.stringify(canvas.toJSON(['abcFieldKey','abcFieldAlign','abcFieldFontFamily','abcFieldFontSize','abcFieldFill']));

    // single surface preview for now
    var previews = {};
    try {
      previews.front = canvas.toDataURL({ format: 'png', multiplier: 2 });
    } catch (e) {
      console.warn('[ABC Draft Editor] Preview generation failed', e);
    }

    post('abc_b2b_save_draft', {
      draft_id: CFG.draft_id,
      payload: payload,
      previews: previews
    }).done(function (res) {
      if (!res || !res.success) {
        alert((res && res.data && res.data.message) ? res.data.message : 'Error saving draft.');
      } else {
        // approvals reset happens on save server-side, so reflect UI
        updateApprovalUI({ employee_ready: 0, admin_ready: 0, ready_override: 0, is_org_admin: isAdmin, can_bypass: canBypass });
      }
    }).fail(function (xhr) {
      console.error('[ABC Draft Editor] save_draft AJAX failed', xhr);
      alert('Server error saving draft. Check console.');
    }).always(function () {
      $saveBtn.prop('disabled', false).text('Save Proof');
    });
  }

  function setReady(action, $btn, doneText) {
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);

    post(action, { draft_id: CFG.draft_id })
      .done(function (res) {
        if (!res || !res.success) {
          alert((res && res.data && res.data.message) ? res.data.message : 'Error updating status.');
          $btn.prop('disabled', false);
          return;
        }
        $btn.text(doneText).prop('disabled', true);
        // refresh draft flags quickly
        loadDraft();
      })
      .fail(function (xhr) {
        console.error('[ABC Draft Editor] status AJAX failed', xhr);
        $btn.prop('disabled', false);
        alert('Server error. Check console.');
      });
  }

  function addToCart() {
    if ($addToCartBtn.prop('disabled')) return;
    $addToCartBtn.prop('disabled', true).text('Adding…');

    post('abc_b2b_add_draft_to_cart', { draft_id: CFG.draft_id })
      .done(function (res) {
        if (res && res.success && res.data && res.data.cart_url) {
          window.location.href = res.data.cart_url;
          return;
        }
        alert((res && res.data && res.data.message) ? res.data.message : 'Could not add to cart.');
        $addToCartBtn.prop('disabled', false).text('Add to Cart');
      })
      .fail(function (xhr) {
        console.error('[ABC Draft Editor] add_to_cart AJAX failed', xhr);
        alert('Server error adding to cart. Check console.');
        $addToCartBtn.prop('disabled', false).text('Add to Cart');
      });
  }

  // -----------------------------
  // Event bindings
  // -----------------------------
  $saveBtn.off('click.abc').on('click.abc', function (e) {
    e.preventDefault();
    saveDraft();
  });

  $employeeBtn.off('click.abc').on('click.abc', function (e) {
    e.preventDefault();
    setReady('abc_b2b_set_employee_ready', $employeeBtn, 'Employee Ready ✓');
  });

  $adminBtn.off('click.abc').on('click.abc', function (e) {
    e.preventDefault();
    setReady('abc_b2b_set_admin_ready', $adminBtn, 'Org Admin Ready ✓');
  });

  $addToCartBtn.off('click.abc').on('click.abc', function (e) {
    e.preventDefault();
    addToCart();
  });

  $tplSelect.off('change.abc').on('change.abc', function () {
    var tid = parseInt($(this).val(), 10);
    if (!tid) return;

    post('abc_b2b_set_draft_template', {
      draft_id: CFG.draft_id,
      template_id: tid
    }).done(function (res) {
      if (!res || !res.success) {
        alert((res && res.data && res.data.message) ? res.data.message : 'Could not change template.');
        return;
      }
      window.location.reload();
    }).fail(function (xhr) {
      console.error('[ABC Draft Editor] set_draft_template failed', xhr);
      alert('Server error changing template. Check console.');
    });
  });

  // -----------------------------
  // Boot
  // -----------------------------
  loadDraft();
});
