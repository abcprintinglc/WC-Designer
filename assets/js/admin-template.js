/* global jQuery, wp */
(function($){
  'use strict';

  function pickImage($surface){
    const frame = wp.media({
      title: 'Select Background Image',
      button: { text: 'Use this image' },
      multiple: false
    });
    frame.on('select', function(){
      const attachment = frame.state().get('selection').first().toJSON();
      $surface.find('.abc-bg-id').val(attachment.id);
      $surface.find('.abc-bg-preview').html('<img src="'+attachment.url+'" alt="">');
    });
    frame.open();
  }

  function addFieldRow(surfaceKey){
    const $surface = $('.abc-surface[data-surface="'+surfaceKey+'"]');
    const $tbody = $surface.find('.abc-fields-body');
    const tmpl = $('#abc-field-row-template').html();

    const idx = $tbody.find('tr').length;
    const html = tmpl
      .replaceAll('__SURFACE__', surfaceKey)
      .replaceAll('__INDEX__', String(idx));
    $tbody.append($(html));
  }

  function init(){
    $(document).on('click', '.abc-pick-bg', function(){
      const $surface = $(this).closest('.abc-surface');
      if(typeof wp === 'undefined' || !wp.media){
        alert('Media library not available.');
        return;
      }
      pickImage($surface);
    });

    $(document).on('click', '.abc-clear-bg', function(){
      const $surface = $(this).closest('.abc-surface');
      $surface.find('.abc-bg-id').val('0');
      $surface.find('.abc-bg-preview').html('<em>No background selected</em>');
    });

    $(document).on('click', '.abc-add-field', function(){
      const surfaceKey = $(this).data('surface');
      addFieldRow(surfaceKey);
    });

    $(document).on('click', '.abc-remove-field', function(){
      $(this).closest('tr').remove();
    });
  }

  $(init);
})(jQuery);
