/**
 * SimpleMappr - create point maps for publications and presentations
 * jQuery SimpleMappr Admin
 *
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @link      http://github.com/dshorthouse/SimpleMappr
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */
/*global SimpleMappr, jQuery, window, document, self, XMLHttpRequest, alert, encodeURIComponent, _gaq */
var SimpleMapprAdmin = (function($, window, sm) {
  'use strict';
  function error(xhr) {
    $('#admin-status').attr('role', 'alert').text((xhr.responseJSON && xhr.responseJSON.error) || $('#mapper-loading-error-message').text());
  }
  function request(options) {
    sm.showSpinner(); $('#admin-status').text('');
    return $.ajax(Object.assign({timeout: 30000}, options)).fail(error).always(function() { sm.hideSpinner(); });
  }
  function link(url, text) {
    try {
      var parsed = new window.URL(url);
      if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) { return document.createTextNode(text); }
      return $('<a>').attr({href: parsed.href, rel: 'noopener noreferrer'}).text(text)[0];
    } catch (error) { return document.createTextNode(text); }
  }
  function citations() {
    request({url: sm.settings.baseUrl + '/citation.json', dataType: 'json'}).done(function(data) {
      var list = $('#admin-citations-list').empty();
      $('<h2>').text($('#admin-total-label').text() + ' ' + data.citations.length).appendTo(list);
      data.citations.forEach(function(item) {
        var row = $('<p>').addClass('citation').text(item.reference + ' ');
        if (item.doi) { row.append(link('https://doi.org/' + encodeURI(item.doi), item.doi), ' '); }
        if (item.link) { row.append(link(item.link, $('#admin-link-label').text()), ' '); }
        $('<button type="button">').addClass('citation-update').attr('data-id', item.id).text($('#admin-update-label').text()).appendTo(row);
        $('<button type="button">').addClass('citation-delete').attr('data-id', item.id).text($('#button-titles span.delete').text()).appendTo(row);
        row.appendTo(list);
      });
    });
  }
  function users(data) {
    request({url: sm.settings.baseUrl + '/user', data: data || {}, dataType: 'html'}).done(function(html) { $('#userdata').html(html); });
  }
  function clearForm() { $('#map-admin .citation').val('').removeClass('ui-state-error'); }
  function save(method, url) {
    request({type: method, url: sm.settings.baseUrl + url, data: $('#map-admin :input').serialize(), dataType: 'json'}).done(function(data) {
      if (data.status === 'ok') { clearForm(); citations(); }
      else { error({responseJSON: data}); }
    });
  }
  function init() {
    $('#map-admin').off('.simplemapprAdmin')
      .on('click.simplemapprAdmin', '#flush-caches', function(event) {
        event.preventDefault(); request({type: 'POST', url: sm.settings.baseUrl + '/flush_cache', dataType: 'json'}).done(function() { $('#admin-status').text($('#admin-assets-label').text()); });
      })
      .on('click.simplemapprAdmin', 'button.addmore', function(event) { event.preventDefault(); save('POST', '/citation'); })
      .on('click.simplemapprAdmin', 'button.update', function(event) {
        event.preventDefault(); var id = $('#citation-id').val();
        if (/^\d+$/.test(id)) { save('PUT', '/citation/' + id); }
      })
      .on('click.simplemapprAdmin', '.citation-update', function(event) {
        event.preventDefault(); var id = $(this).attr('data-id');
        request({url: sm.settings.baseUrl + '/citation/' + id + '.json', dataType: 'json'}).done(function(data) {
          Object.keys(data).forEach(function(key) { $('#map-admin').find('[name="citation[' + key + ']"]').val(data[key]); });
          $('#citation-reference').trigger('focus')[0].scrollIntoView({block: 'center'});
        });
      })
      .on('click.simplemapprAdmin', '.citation-delete', function(event) {
        event.preventDefault(); var id = $(this).attr('data-id'), trigger = this;
        $('#mapper-message-delete').find('span').text($(this).parent().text()).end().dialog({modal: true, closeOnEscape: true, width: 500,
          buttons: [{text: $('#button-titles span.delete').text(), click: function() {
            var dialog = $(this); request({type: 'DELETE', url: sm.settings.baseUrl + '/citation/' + id, dataType: 'json'}).done(function() { dialog.dialog('destroy'); citations(); });
          }}, {text: $('#button-titles span.cancel').text(), click: function() { $(this).dialog('destroy'); $(trigger).trigger('focus'); }}]
        });
      });
    $('#userdata').off('.simplemapprAdmin').on('click.simplemapprAdmin', 'a.toolsRefresh, a.ui-icon-triangle-sort', function(event) {
      event.preventDefault(); users($(this).attr('data-sort') ? {sort: $(this).attr('data-sort'), dir: $(this).hasClass('asc') ? 'desc' : 'asc'} : {});
    });
    users(); citations();
    request({url: sm.settings.baseUrl + '/apilog', dataType: 'html'}).done(function(html) { $('#admin-api-list').html(html); });
  }
  return {init: init};
}(jQuery, window, SimpleMappr));
