/* global jQuery, interact */
(function($){
  'use strict';

  function parseNum(v, d){ v = parseFloat(v); return isNaN(v) ? d : v; }

  function surfaceInputs($surface){
    const trimW = parseNum($surface.find('input[name*="[trim_w_in]"]').val(), 3.5);
    const trimH = parseNum($surface.find('input[name*="[trim_h_in]"]').val(), 2.0);
    const bleed = parseNum($surface.find('input[name*="[bleed_in]"]').val(), 0.125);
    const safe  = parseNum($surface.find('input[name*="[safe_in]"]').val(), 0.125);
    const bgUrl = $surface.find('.abc-bg-preview img').attr('src') || '';
    return { trimW, trimH, bleed, safe, bgUrl };
  }

  function findFieldRow($surface, key){
    const rows = $surface.find('.abc-fields-body tr.abc-field-row');
    let found = null;
    rows.each(function(){
      const $r = $(this);
      const k = ($r.find('input[name*="[key]"]').val() || '').trim();
      if(k === key){ found = $r; return false; }
    });
    return found;
  }

  function buildBoxes($surface, cfg, $builder){
    $builder.empty();

    const totalW = cfg.trimW + 2*cfg.bleed;
    const totalH = cfg.trimH + 2*cfg.bleed;

    // Fit width
    const maxPxW = 820;
    const scale = maxPxW / totalW; // px per inch
    const wPx = Math.round(totalW * scale);
    const hPx = Math.round(totalH * scale);

    const $stage = $('<div class="abc-stage"/>').css({ width: wPx+'px', height: hPx+'px' });
    const $bg = $('<div class="abc-stage-bg"/>');
    if(cfg.bgUrl){
      $bg.css('background-image', 'url("'+cfg.bgUrl+'")');
    }
    $stage.append($bg);

    // Guides
    const $bleedGuide = $('<div class="abc-guide abc-guide-bleed"/>').css({ left: 0, top: 0, width: wPx, height: hPx });
    const $trimGuide  = $('<div class="abc-guide abc-guide-trim"/>').css({
      left: Math.round(cfg.bleed*scale),
      top: Math.round(cfg.bleed*scale),
      width: Math.round(cfg.trimW*scale),
      height: Math.round(cfg.trimH*scale)
    });
    const $safeGuide  = $('<div class="abc-guide abc-guide-safe"/>').css({
      left: Math.round((cfg.bleed+cfg.safe)*scale),
      top: Math.round((cfg.bleed+cfg.safe)*scale),
      width: Math.round((cfg.trimW-2*cfg.safe)*scale),
      height: Math.round((cfg.trimH-2*cfg.safe)*scale)
    });
    $stage.append($bleedGuide, $trimGuide, $safeGuide);

    // Build field boxes
    const keys = [];
    $surface.find('.abc-fields-body tr.abc-field-row').each(function(){
      const $r = $(this);
      const key = ($r.find('input[name*="[key]"]').val() || '').trim();
      if(!key) return;

      const leftIn = parseNum($r.find('input[name*="[left_in]"]').val(), 0.25);
      const topIn  = parseNum($r.find('input[name*="[top_in]"]').val(), 0.25);
      const wIn    = parseNum($r.find('input[name*="[width_in]"]').val(), 1.0);
      const hIn    = parseNum($r.find('input[name*="[height_in]"]').val(), 0.25);
      const label  = ($r.find('input[name*="[label]"]').val() || key).trim();

      const x = Math.round((cfg.bleed + leftIn) * scale);
      const y = Math.round((cfg.bleed + topIn) * scale);
      const w = Math.max(18, Math.round(wIn * scale));
      const h = Math.max(14, Math.round(hIn * scale));

      const $box = $('<div class="abc-field-box"/>')
        .attr('data-key', key)
        .css({ left: x+'px', top: y+'px', width: w+'px', height: h+'px' });

      const $tag = $('<div class="abc-field-tag"/>').text(label);
      $box.append($tag);
      $stage.append($box);
      keys.push(key);
    });

    $builder.append($stage);
    $builder.append($('<div class="abc-builder-hint"/>').html(
      '<strong>Tip:</strong> Drag boxes to position. Resize from corners. Positions update the table automatically.'
    ));

    // Make draggable + resizable
    keys.forEach(key=>{
      const el = $stage.find('.abc-field-box[data-key="'+key+'"]').get(0);
      if(!el) return;

      interact(el)
        .draggable({
          listeners: {
            move (event) {
              const target = event.target;
              const x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx;
              const y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;
              target.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
              target.setAttribute('data-x', x);
              target.setAttribute('data-y', y);
            },
            end (event) {
              commitBox(event.target);
            }
          }
        })
        .resizable({
          edges: { left: true, right: true, bottom: true, top: true },
          listeners: {
            move (event) {
              const target = event.target;
              let x = (parseFloat(target.getAttribute('data-x')) || 0);
              let y = (parseFloat(target.getAttribute('data-y')) || 0);

              target.style.width = event.rect.width + 'px';
              target.style.height = event.rect.height + 'px';

              x += event.deltaRect.left;
              y += event.deltaRect.top;

              target.style.transform = 'translate(' + x + 'px,' + y + 'px)';
              target.setAttribute('data-x', x);
              target.setAttribute('data-y', y);
            },
            end (event) {
              commitBox(event.target);
            }
          },
          modifiers: [
            interact.modifiers.restrictSize({
              min: { width: 40, height: 18 }
            })
          ],
          inertia: true
        });
    });

    function commitBox(target){
      const key = target.getAttribute('data-key');
      const $row = findFieldRow($surface, key);
      if(!$row) return;

      const baseLeft = parseFloat(target.style.left) || 0;
      const baseTop  = parseFloat(target.style.top) || 0;
      const transX = parseFloat(target.getAttribute('data-x')) || 0;
      const transY = parseFloat(target.getAttribute('data-y')) || 0;

      const leftPx = baseLeft + transX;
      const topPx = baseTop + transY;

      const wPxNow = parseFloat(target.style.width) || 0;
      const hPxNow = parseFloat(target.style.height) || 0;

      // Convert to inches from trim top-left (not including bleed)
      const leftIn = (leftPx / scale) - cfg.bleed;
      const topIn  = (topPx / scale) - cfg.bleed;
      const wIn    = (wPxNow / scale);
      const hIn    = (hPxNow / scale);

      $row.find('input[name*="[left_in]"]').val(leftIn.toFixed(3));
      $row.find('input[name*="[top_in]"]').val(topIn.toFixed(3));
      $row.find('input[name*="[width_in]"]').val(wIn.toFixed(3));
      $row.find('input[name*="[height_in]"]').val(hIn.toFixed(3));
    }
  }

  function openBuilder(surfaceKey){
    const $surface = $('.abc-surface[data-surface="'+surfaceKey+'"]');
    const $builder = $('#abc_builder_'+surfaceKey);
    const cfg = surfaceInputs($surface);
    buildBoxes($surface, cfg, $builder);
    $builder.show();
  }

  function refreshBuilder(surfaceKey){
    openBuilder(surfaceKey);
  }

  function init(){
    $(document).on('click', '.abc-open-builder', function(){
      const surfaceKey = $(this).data('surface');
      openBuilder(surfaceKey);
    });
    $(document).on('click', '.abc-refresh-builder', function(){
      const surfaceKey = $(this).data('surface');
      refreshBuilder(surfaceKey);
    });
  }

  $(init);
})(jQuery);
