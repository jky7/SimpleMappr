/**
 * SimpleMappr - create point maps for publications and presentations
 * jQuery SimpleMappr
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
/*global jQuery, window, document, self, FileReader, encodeURIComponent */
var SimpleMappr = (function($, window, document) {

  "use strict";

  var _private = {

    settings: {
      baseUrl: '',
      active: false,
      maxTextareaCount: 10,
      undoSize: 10,
      csrfToken: "",
      downloadTimeout: 90000,
      storageScope: "guest",
      migrateLegacyStorage: false
    },

    vars: {
      newPointCount      : 0,
      newRegionCount     : 0,
      newWKTCount        : 0,
      zoom               : true,
      renderRequest      : null,
      renderSequence     : 0,
      fillColor          : "",
      jCropType          : "zoom",
      cropUpdated        : false,
      origins            : { "esri:102009" : -96,
                           "esri:102015" : -60,
                           "esri:102014" : 10,
                           "esri:102012" : 105,
                           "esri:102024" : 25,
                           "epsg:3112" : 134
                           },
      spinner            : $('#map-loader').find('span.mapper-loading-spinner'),
      fieldSetsPoints    : $('#fieldSetsPoints'),
      fieldSetsRegions   : $('#fieldSetsRegions'),
      fieldSetsWKT       : $('#fieldSetsWKT'),
      mapOutput          : $('#mapOutput'),
      mapOutputImage     : $('#mapOutputImage')
    },

    getPageSize: function() {
      var xScroll, yScroll, windowWidth, windowHeight, pageHeight, pageWidth;

      if (window.innerHeight && window.scrollMaxY) {
        xScroll = window.innerWidth + window.scrollMaxX;
        yScroll = window.innerHeight + window.scrollMaxY;
      } else if (document.body.scrollHeight > document.body.offsetHeight) { // all but Explorer Mac
        xScroll = document.body.scrollWidth;
        yScroll = document.body.scrollHeight;
      } else { // Explorer Mac...would also work in Explorer 6 Strict, Mozilla and Safari
        xScroll = document.body.offsetWidth;
        yScroll = document.body.offsetHeight;
      }

      if (self.innerHeight) { // all except Explorer
        if(document.documentElement.clientWidth) {
          windowWidth = document.documentElement.clientWidth;
        } else {
          windowWidth = self.innerWidth;
        }
        windowHeight = self.innerHeight;
      } else if (document.documentElement && document.documentElement.clientHeight) { // Explorer 6 Strict Mode
        windowWidth = document.documentElement.clientWidth;
        windowHeight = document.documentElement.clientHeight;
      } else if (document.body) { // other Explorers
        windowWidth = document.body.clientWidth;
        windowHeight = document.body.clientHeight;
      }
      // for small pages with total height less then height of the viewport
      if(yScroll < windowHeight) {
        pageHeight = windowHeight;
      } else {
        pageHeight = yScroll;
      }
      // for small pages with total width less then width of the viewport
      if(xScroll < windowWidth) {
        pageWidth = xScroll;
      } else {
        pageWidth = windowWidth;
      }

      return [pageWidth,pageHeight,windowWidth,windowHeight];
    },

    getPageScroll: function() {
      var xScroll, yScroll;

      if (self.pageYOffset) {
        yScroll = self.pageYOffset;
        xScroll = self.pageXOffset;
      } else if (document.documentElement && document.documentElement.scrollTop) {
        yScroll = document.documentElement.scrollTop;
        xScroll = document.documentElement.scrollLeft;
      } else if (document.body) {// all other Explorers
        yScroll = document.body.scrollTop;
        xScroll = document.body.scrollLeft;
      }

      return [xScroll,yScroll];
    },

    showCoords: function(c) {
      var self      = this,
          x         = parseFloat(c.x),
          y         = parseFloat(c.y),
          x2        = parseFloat(c.x2),
          y2        = parseFloat(c.y2),
          w         = parseFloat(c.w),
          h         = parseFloat(c.h),
          ul_holder = '<input type="text" id="jcrop-coord-ul" class="jcrop-coord"></input>',
          lr_holder = '<input type="text" id="jcrop-coord-lr" class="jcrop-coord"></input>',
          d_holder  = '<div id="jcrop-dimension-wrapper"><input type="text" id="jcrop-dimension-w" class="jcrop-dimension"></input>X<input type="text" id="jcrop-dimension-h" class="jcrop-dimension"></input></div>',
          ul_point  = { 'x' : x, 'y' : y },
          lr_point  = { 'x' : x2, 'y' : y2 },
          ul_coord  = {},
          lr_coord  = {},
          factor    = $('#mapExport').find('input[name="download-factor"]:checked').val();

      switch(this.vars.jCropType) {
        case 'crop':
          self.vars.mapOutput.find('div.jcrop-holder div:first').css({backgroundColor:'white'});
          $('#bbox_rubberband').val(x+','+y+','+x2+','+y2);

          if($('#projection option:selected').val() === 'epsg:4326') {
            self.vars.mapOutput.find('input.jcrop-coord').css({width: "100px"});
          } else {
            self.vars.mapOutput.find('input.jcrop-coord').css({width: "175px"});
          }

          if($('#jcrop-coord-ul').length === 0 && $('#jcrop-coord-lr').length === 0) {
            self.vars.mapOutput.find('div.jcrop-tracker').eq(0).after(ul_holder).after(lr_holder).after(d_holder);
          }

          ul_coord = self.pix2geo(ul_point);
          lr_coord = self.pix2geo(lr_point);
          $('#jcrop-coord-ul').val(ul_coord.x + ', ' + ul_coord.y);
          $('#jcrop-coord-lr').val(lr_coord.x + ', ' + lr_coord.y);
          $('#jcrop-dimension-w').val(w);
          $('#jcrop-dimension-h').val(h);
          $('#jcrop-dimension-wrapper').css({left : w/2-$('#jcrop-dimension-wrapper').width()/2, top : h/2-$('#jcrop-dimension-wrapper').height()/2});

          window.SimpleMapprStorage.crop( "{ \"jcrop_coord_ul\" : \"" + $('#jcrop-coord-ul').val() + "\", \"jcrop_coord_lr\" : \"" + $('#jcrop-coord-lr').val() + "\" }" );

          self.vars.mapOutput.on('blur', 'input.jcrop-coord', function() {
            if(!self.vars.cropUpdated) { self.vars.cropUpdated = self.updateCropCoordinates(); }
          })
          .on('keypress', 'input.jcrop-coord', function(e) {
            var key = e.keyCode || e.which;
            if(key === 13) {
              e.preventDefault();
              self.vars.cropUpdated = false;
              this.blur();
            }
          }).on('blur', 'input.jcrop-dimension', function() {
            if(!self.vars.cropUpdated) { self.vars.cropUpdated = self.updateCropDimensions(); }
          })
          .on('keypress', 'input.jcrop-dimension', function(e) {
            var key = e.keyCode || e.which;
            if(key === 13) {
              e.preventDefault();
              self.vars.cropUpdated = false;
              this.blur();
            }
          });

          $('#scale-measure').find('span').text(factor*w + ' X ' + factor*h).parent().show();
        break;

        case 'zoom':
          self.vars.mapOutput.find('div.jcrop-holder div:first').css({backgroundColor: 'white'});
          $('#bbox_rubberband').val(x+','+y+','+x2+','+y2);
        break;

        case 'query':
          $('#bbox_query').val(x+','+y+','+x2+','+y2);
        break;
      }
    },

    updateCropDimensions: function() {
      var rubberband   = $('#bbox_rubberband').val().split(","),
          rubberband_w = parseFloat(rubberband[2])-parseFloat(rubberband[0]),
          rubberband_h = parseFloat(rubberband[3])-parseFloat(rubberband[1]),
          image_w      = this.vars.mapOutputImage.width(),
          image_h      = this.vars.mapOutputImage.height(),
          w            = $('#jcrop-dimension-w').val(),
          h            = $('#jcrop-dimension-h').val(),
          x            = rubberband[0],
          x2           = rubberband[2],
          y            = rubberband[1],
          y2           = rubberband[3];

      w = (w > image_w) ? image_w : (rubberband_w - w)/2;
      h = (h > image_h) ? image_h : (rubberband_h - h)/2;
      x  = parseFloat(rubberband[2])-w;
      x2 = parseFloat(rubberband[0])+w;
      y  = parseFloat(rubberband[1])+h;
      y2 = parseFloat(rubberband[3])-h;

      if(w >= image_w) {
        x  = 0;
        x2 = image_w;
      }
      if(h >= image_h) {
        y  = 0;
        y2 = image_h;
      }

      this.loadCropSettings({ 'map' : { 'bbox_rubberband' : x.toString() + "," + y.toString() + "," + x2.toString() + "," + y2.toString() } });
      return true;
    },

    updateCropCoordinates: function() {
      var ul_val   = $('#jcrop-coord-ul').val(),
          ul_arr   = ul_val.split(","),
          ul_point = this.geo2pix({ 'x' : $.trim(ul_arr[0]), 'y' : $.trim(ul_arr[1]) }),
          lr_val   = $('#jcrop-coord-lr').val(),
          lr_arr   = lr_val.split(","),
          lr_point = this.geo2pix({ 'x' : $.trim(lr_arr[0]), 'y' : $.trim(lr_arr[1]) });

      window.SimpleMapprStorage.crop( "{ \"jcrop_coord_ul\" : \"" + ul_val + "\", \"jcrop_coord_lr\" : \"" + lr_val + "\" }" );
      this.loadCropSettings({ 'map' : { 'bbox_rubberband' : lr_point.x + "," + lr_point.y + "," + ul_point.x + "," + ul_point.y } });
      return true;
    },

    pix2geo: function(point) {
      var deltaX = 0,
          deltaY = 0,
          bbox   = $('#bbox_map').val(),
          width  = parseFloat(this.vars.mapOutputImage.width()),
          height = parseFloat(this.vars.mapOutputImage.height()),
          geo    = {};

      if(bbox === "") {
        bbox = "-180,-90,180,90";
      }
      bbox = bbox.split(",");

      deltaX = Math.abs(parseFloat($.trim(bbox[2])) - parseFloat($.trim(bbox[0])));
      deltaY = Math.abs(parseFloat($.trim(bbox[3])) - parseFloat($.trim(bbox[1])));

      geo.x = this.roundNumber(parseFloat(bbox[0]) + (parseFloat(point.x)*deltaX)/width,2);
      geo.y = this.roundNumber(parseFloat(bbox[1]) + (parseFloat(height - parseFloat(point.y))*deltaY)/height,2);

      return geo;
    },

    geo2pix: function(coord) {
      var deltaX = 0,
          deltaY = 0,
          bbox   = $('#bbox_map').val(),
          point  = {};

      if(bbox === "") {
        bbox = "-180,-90,180,90";
      }
      bbox = bbox.split(",");

      deltaX = Math.abs(parseFloat($.trim(bbox[2])) - parseFloat($.trim(bbox[0])));
      deltaY = Math.abs(parseFloat($.trim(bbox[3])) - parseFloat($.trim(bbox[1])));

      point.x = this.vars.mapOutputImage.width()*(Math.abs(parseFloat(coord.x) - parseFloat($.trim(bbox[0]))))/deltaX;
      point.y = this.vars.mapOutputImage.height()*(deltaY - Math.abs(parseFloat(coord.y) - parseFloat($.trim(bbox[1]))))/deltaY;

      return point;
    },

    roundNumber: function(num, dec) {
      return Math.round(num*Math.pow(10,dec))/Math.pow(10,dec);
    },

    tabSelector: function(tab) {
      $("#tabs").tabs("enable", tab).tabs("option", "active", tab);
      this.accessibleTabs($('#tabs'));
      this.updateTabHash(tab);
    },

    RGBtoHex: function(R,G,B) {
      return this.toHex(R)+this.toHex(G)+this.toHex(B);
    },

    toHex: function(N) {
      if (N === null) { return "00"; }
      N = parseInt(N, 10);
      if (N === 0 || isNaN(N)) { return "00"; }
      N = Math.max(0,N);
      N = Math.min(N,255);
      N = Math.round(N);
      return "0123456789ABCDEF".charAt((N-N%16)/16) + "0123456789ABCDEF".charAt(N%16);
    },

    bindToolbar: function() {
      var self = this, action = "";

      $("#actionsBar").find("li").hover(function() {
        $(this).toggleClass("ui-state-hover");
      }).end().find("a.toolsQuery").ColorPicker({
        onBeforeShow: function() {
          $(this).ColorPickerSetColor(self.RGBtoHex(150, 150, 150));
        },
        onShow: function(colpkr) {
          $(colpkr).show();
          self.destroyJcrop();
          return false;
        },
        onHide: function(colpkr) {
          $(colpkr).hide();
          return false;
        },
        onSubmit: function(hsb, hex, rgb, el) {
          self.unusedVariables(hsb, hex);
          $(el).ColorPickerHide();
          self.vars.fillColor = rgb;
          self.initJquery();
          self.vars.zoom = false;
        }
      }).end().on('click', 'a', function(e) {
        e.preventDefault();
        if(!self.missingFieldSetTitle()) {
          action = $(this).attr("class").match(/tools([\w\W]+)/)[1].toLowerCase();
          switch(action) {
            case 'zoomin':
              self.mapZoom("in");
            break;

            case 'zoomout':
              self.mapZoom("out");
            break;

            case 'crop':
              self.mapCrop();
            break;

            case 'query':
              self.resetJbbox();
            break;

            case 'new':
              self.mapNew();
            break;

            case 'refresh':
              self.mapRefresh();
            break;

            case 'rebuild':
              self.mapRebuild();
            break;

            case 'save':
              self.mapSave();
            break;

            case 'download':
              self.mapDownload();
            break;
          }

        }
      });
    },

    mapCrop: function() {
      var coords   = {},
          ul_arr   = [],
          ul_point = {},
          lr_arr   = [],
          lr_point = {};

      if(window.SimpleMapprStorage.crop()) {
        coords = $.parseJSON(window.SimpleMapprStorage.crop());
        ul_arr = coords.jcrop_coord_ul.split(",");
        lr_arr = coords.jcrop_coord_lr.split(",");
        ul_point = this.geo2pix({ 'x' : $.trim(ul_arr[0]), 'y' : $.trim(ul_arr[1]) });
        lr_point = this.geo2pix({ 'x' : $.trim(lr_arr[0]), 'y' : $.trim(lr_arr[1]) });
        this.loadCropSettings({ 'map' : { 'bbox_rubberband' : lr_point.x + "," + lr_point.y + "," + ul_point.x + "," + ul_point.y } });
      } else {
        this.initJcrop();
      }

      this.vars.zoom = false;
    },

    resetAndBuild: function() {
      this.resetJbbox();
      this.destroyRedo();
      this.showMap();
    },

    mapNew: function() {
      var self = this;

      $('#mapOptions').find("input").prop("checked", false);
      $('#countries').prop("checked", true);
      $('#gridspace').prop("checked", true);
      $('#mapTitle').html('');
      $('#m-mapSaveTitle').val('');
      $('#file-name').val('');
      $('#actionsBar').find('a.toolsEmbed').css({display:'none'});
      $('#border_thickness').val(1.25);
      $('#border-slider').slider({value:1.25});
      $('#clearLayers, #clearRegions, #clearWKT').each(function() {
        self.clearZone($(this).parent().prev().prev().children());
      });
      this.resetJbbox();
      this.mapRebuild();
    },

    mapRefresh: function() {
      this.resetAndBuild();
      this.tabSelector(0);
    },

    mapRebuild: function() {
      var self = this;

      $.each(['bbox_map', 'projection_map', 'origin_map', 'bbox_rubberband', 'rotation', 'pan'], function(key, value) {
        self.unusedVariables(key);
        $('#' + value).val('');
      });
      this.setRotation();
      $('#projection')[0].selectedIndex = 0;
      this.destroyRedo();
      this.showMap();
    },

    bindArrows: function() {
      var self = this;

      $('#wheel-overlay').on('click', 'a.arrows', function(e) {
        e.preventDefault();
        $('#pan').val($(this).attr("data-pan"));
        self.resetJbbox();
        self.showMap();

      });
    },

    mapPan: function(dir) {
      $('#pan').val(dir);
      this.resetAndBuild();
    },

    mapList: function() {
      this.tabSelector(4);
    },

    mapZoom: function(dir) {
      var zoom = "";

      switch(dir) {
        case "in":
          this.initJzoom();
          this.vars.zoom = true;
        break;

        case "in-auto":
          zoom = $('#zoom_in');
          this.resetJbbox();
          zoom.val(1);
          this.destroyRedo();
          this.showMap();
          zoom.val('');
        break;

        case "out":
          zoom = $('#zoom_out');
          this.resetJbbox();
          zoom.val(1);
          this.destroyRedo();
          this.showMap();
          zoom.val('');
        break;
      }
    },

    storageType: function(type) {
      var index = 0, self = this;

      index = $.grep(window.SimpleMapprStorage.index(), function(value, i) {
        self.unusedVariables(i);
        return (value.substring(0, type.length) === type);
      });

      return index;
    },

    toggleUndo: function(activate) {
      var self  = this,
          index = this.storageType("do"),
          actionsBar = $('#actionsBar');

      actionsBar.find('a.toolsUndo').removeClass('toolsUndo').addClass('toolsUndoDisabled').off('click');

      if(activate && index.length > 1) {
        if(index.length > self.settings.undoSize) { window.SimpleMapprStorage.deleteKey(index.shift()); }
        actionsBar.find('a.toolsUndoDisabled').addClass('toolsUndo').removeClass('toolsUndoDisabled').on('click', function(e) {
          e.preventDefault();
          self.mapUndo();

        });
      }
    },

    toggleRedo: function(activate) {
      var self = this,
          actionsBar = $('#actionsBar');

      actionsBar.find('a.toolsRedo').addClass('toolsRedoDisabled').removeClass('toolsRedo').off('click');

      if(activate) {
        actionsBar.find('a.toolsRedoDisabled').addClass('toolsRedo').removeClass('toolsRedoDisabled').on('click', function(e) {
          e.preventDefault();
          self.mapRedo();

        });
      }
    },

    destroyRedo: function() {
      var index = this.storageType("undo"),
          actionsBar = $('#actionsBar');

      if(index.length > 0) {
        actionsBar.find('a.toolsRedo').addClass('toolsRedoDisabled').removeClass('toolsRedo').off('click');
        window.SimpleMapprStorage.deleteKey(index.pop());
      }
    },

    mapUndo: function() {
      var index          = this.storageType("do"),
          curr_key       = "",
          curr_data      = {},
          prev_key       = "",
          prev_data      = {},
          prev_data_prep = {};

      if(index.length === 1) { return; }

      this.destroyRedo();

      curr_key       = index[index.length-1];
      curr_data      = window.SimpleMapprStorage.get(curr_key);
      prev_key       = index[index.length-2];
      prev_data      = window.SimpleMapprStorage.get(prev_key);
      prev_data_prep = this.prepareInputs(prev_data);

      this.loadInputs(prev_data_prep);

      window.SimpleMapprStorage.deleteKey(curr_key);
      window.SimpleMapprStorage.set("un" + curr_key, curr_data);

      if(prev_data.width !== curr_data.width) {
        this.mapToggleSettings();
      } else {
        this.showSpinner();
        this.postData($.param(prev_data), null);
      }

      this.toggleRedo(true);
      if(index.length === 2) { this.toggleUndo(); }
    },

    mapRedo: function() {
      var undo_index     = this.storageType("undo"),
          undo_key       = "",
          undo_data      = {},
          undo_data_prep = {},
          do_index       = this.storageType("do"),
          do_key         = "",
          do_data        = {},
          token          = new Date().getTime();

      if(undo_index.length === 0) { return; }

      this.toggleRedo();
      undo_key       = undo_index.pop();
      undo_data      = window.SimpleMapprStorage.get(undo_key);
      do_key         = do_index[do_index.length-1];
      do_data        = window.SimpleMapprStorage.get(do_key);
      undo_data_prep = this.prepareInputs(undo_data);
      this.loadInputs(undo_data_prep);

      window.SimpleMapprStorage.deleteKey(undo_key);
      window.SimpleMapprStorage.set("do-" + token.toString(), undo_data);

      if(undo_data.width !== do_data.width) {
        this.mapToggleSettings();
      } else {
        this.showSpinner();
        this.postData($.param(undo_data), null);
      }

      this.toggleUndo(true);
    },

    bindHotkeys: function() {
      var self = this, map = this.vars.mapOutput;
      map.attr({tabindex: 0, role: 'region', 'aria-label': $('#mapper-preview-label').text()});
      map.on('keydown.simplemappr', function(event) {
        if ($(event.target).is('input,textarea,select,button,[contenteditable]') || $('.ui-dialog:visible').length) { return; }
        var action = null, key = event.key.toLowerCase();
        if (event.ctrlKey || event.metaKey) {
          var actions = {s: 'mapSave', d: 'mapDownload', l: 'mapList', n: 'mapNew', r: 'mapRefresh', b: 'mapRebuild', x: 'mapCrop', e: 'mapToggleSettings', z: 'mapUndo', y: 'mapRedo'};
          action = actions[key];
          if (self.settings.active === 'false' && (key === 's' || key === 'l')) { return; }
          if (action) { event.preventDefault(); self[action](); }
        } else if (key === 'escape') { self.destroyJcrop(); }
        else if (['arrowup','arrowdown','arrowleft','arrowright'].includes(key)) { event.preventDefault(); self.mapPan(key.slice(5)); }
        else if (key === '+' || key === '=') { event.preventDefault(); self.mapZoom('in'); }
        else if (key === '-') { event.preventDefault(); self.mapZoom('out'); }
      });
      this.vars.mapOutputImage.on('dblclick.simplemappr', function(event) { if (!self.missingFieldSetTitle()) { self.dblclickZoom(this, event); } });
    },

    hardResetShowMap: function(e) {
      if(this.missingFieldSetTitle()) {
        e.preventDefault();
        e.stopPropagation();
      } else {
        this.resetJbbox();
        this.destroyRedo();
        this.showMap();

      }
    },

    bindSettings: function() {
      var self = this, graticules = $('#grid');

      $('#mapOptions').on('click', '.layeropt', function(e) {
        self.hardResetShowMap(e);
      }).on('click', '.gridopt', function(e) {
        if(!graticules.prop('checked')) { graticules.prop('checked', true); }
        self.hardResetShowMap(e);
      }).on('click', '#gridlabel', function(e) {
        if(!graticules.prop('checked')) { graticules.prop('checked', true); }
        self.hardResetShowMap(e);
      });

      $('#projection').on('change', function(e) {
        var origin_sel = $('#origin-selector');

        if($(this).val() !== "") {
          $('#origin').val(self.vars.origins[$(this).val()]);
          if(self.vars.origins.hasOwnProperty($("#projection").val())) { origin_sel.show(); } else { origin_sel.hide(); }
          window.SimpleMapprStorage.crop( null);
          self.hardResetShowMap(e);
        }
      });

      $('#origin').on('blur', function(e) {
        self.hardResetShowMap(e);
      }).on('keydown', function(e) {
        var key = e.keyCode || e.which;
        if(key === 9 || key === 13 ) { this.blur(); }
      });

      self.toggleFileFactor();

      $('#mapExport').find('input.download-factor').on('change', function() {
        self.toggleFileFactor($(this).val());
      }).end().find('.download-filetype').on('change', function() {
        self.toggleFileType(this);
      });
    },

    bindSlider: function() {
      var self = this;

      $("#border-slider").slider({
        value : 1.25,
        min   : 1,
        max   : 2,
        step  : 0.25,
        slide: function(e, ui) {
          self.unusedVariables(e);
          if(!self.missingFieldSetTitle()) {
            $('#border_thickness').val(ui.value);
            self.destroyRedo();
            self.showMap();

          } else {
            return false;
          }
        }
      });
    },

    toggleFileFactor: function(factor) {
      var scale      = "",
          rubberband = $('#bbox_rubberband').val().split(",");

      if(!factor) { factor = $('#mapExport').find('input[name="download-factor"]:checked').val(); }

      if(this.vars.jCropType === 'crop') {
        scale = factor*(rubberband[2]-rubberband[0]) + " X " + factor*(rubberband[3]-rubberband[1]);
      } else {
        scale = factor*(this.vars.mapOutputImage.width()) + " X " + factor*(this.vars.mapOutputImage.height());
      }
      $('#scale-measure').find('span').text(scale).parent().show();
    },

    toggleFileType: function() {
      $('#legend, #scalebar, #border, #scalelinethickness').prop('disabled', false);
    },

    bindColorPickers: function() {
      var self = this;
      $.each([this.vars.fieldSetsPoints, this.vars.fieldSetsRegions, this.vars.fieldSetsWKT], function(){
        $(this).find('input.colorPicker').ColorPicker({
          element : $(this),
          onBeforeShow: function() {
            var color = $(this).val().split(" ");
            $(this).ColorPickerSetColor(self.RGBtoHex(color[0], color[1], color[2]));
          },
          onHide: function(colpkr) {
            $(colpkr).hide();
            return false;
          },
          onSubmit: function(hsb, hex, rgb, el) {
            self.unusedVariables(hsb,hex);
            $(el).val(rgb.r + ' ' + rgb.g + ' ' + rgb.b).ColorPickerHide();
          }
        }).on('keyup', function() {
          var color = $(this).val().split(" ");
          $(this).ColorPickerSetColor(self.RGBtoHex(color[0], color[1], color[2]));
        });
      });
    },

    bindClearButtons: function() {
      var self = this;

      $('#clearLayers, #clearWKT, #clearRegions').on('click', function(e) {
        e.preventDefault();
        self.clearZone($(this).parent().prev().prev().children());
      });

      $.each([this.vars.fieldSetsPoints, this.vars.fieldSetsRegions, this.vars.fieldSetsWKT], function() {
        $(this).on('click', 'button.clearself', function(e) {
          e.preventDefault();
          self.clearZone($(this).parent().parent());
        });
      });
    },

    clearZone: function(zone) {
      var self = this,
          shape_picker = zone.find('select.m-mapShape'),
          size_picker = zone.find('select.m-mapSize'),
          color_picker = zone.find('input.colorPicker'),
          shadow_check = zone.find('input.m-mapShadow'),
          border_check = zone.find('input.m-mapBorder'),
          hatch_check = zone.find('input.m-mapHatch');

      $.each(['input.m-mapTitle', 'textarea'], function(key, value) {
        self.unusedVariables(key);
        zone.find(value).val('');
      });
      if(shape_picker.length > 0) { shape_picker[0].selectedIndex = 4; }
      if(size_picker.length > 0) { size_picker[0].selectedIndex = 3; }
      if(shadow_check.length > 0) { shadow_check.prop('checked', false); }
      if(border_check.length > 0) { border_check.prop('checked', false); }
      if(hatch_check.length > 0) { hatch_check.prop('checked', false); }
      $.each(zone, function() {
        if($(this).hasClass("fieldset-points")) {
          color_picker.val('0 0 0');
        } else {
          color_picker.val('150 150 150');
        }
      });
    },

    bindAutocomplete: function() {
      var self = this, term = "", terms = [];

      this.vars.fieldSetsRegions.find('textarea').on('keydown', function(e) {
        if (e.keyCode === $.ui.keyCode.TAB && $(this).data("autocomplete").menu.active) { e.preventDefault(); }
      }).autocomplete({
        source: function(request, response) {
          $.getJSON( "/places.json?term=" + self.extractLast(request.term), {}, response);
        },
        search: function() {
          term = self.extractLast(this.value);
          if (term.length < 2) { return false; }
        },
        focus: function() { return false; },
        select: function(e, ui) {
          self.unusedVariables(e);
          terms = self.split(this.value);
          terms.pop();
          terms.push(ui.item.value);
          terms.push("");
          this.value = terms.join(", ");
          return false;
        }
      });
    },

    split: function(val,delimiter) {
      switch(delimiter) {
        case '[':
         return val.split(/\]/);

        default:
          return val.split(/,\s*/);
      }
    },

    extractLast: function(term) {
      return this.split(term).pop();
    },

    destroyJcrop: function() {
      var vars = this.vars;

      if(vars.jzoomAPI !== undefined) { vars.jzoomAPI.destroy(); }
      if(vars.jcropAPI !== undefined) { vars.jcropAPI.destroy(); }
      if(vars.jqueryAPI !== undefined) { vars.jqueryAPI.destroy(); }

      this.vars.mapOutputImage.show();
      this.vars.mapOutput.find('div.jcrop-holder').remove();
      $('#mapCropMessage').hide();

      this.toggleFileFactor();
    },

    resetJbbox: function() {
      var self = this;

      this.vars.jCropType = "zoom";
      $.each(['rubberband', 'query'], function(key, value) {
        self.unusedVariables(key);
        $('#bbox_' + value).val('');
      });
      this.toggleFileFactor();
    },

    bindCallback: function(scope, fn) {
      var args = Array.prototype.slice.call(arguments, 2);
      return function() {
        fn.apply(scope, $.extend(arguments, args));
      };
    },

    initJcrop: function(select) {
      var self = this;

      this.destroyJcrop();
      this.resetJbbox();
      this.vars.jCropType = "crop";

      this.vars.jcropAPI = $.Jcrop('#' + self.vars.mapOutputImage.attr("id"), {
        bgColor   : (self.vars.mapOutputImage.attr("src") === "public/images/basemap.png") ? 'grey' : 'black',
        bgOpacity : 0.5,
        onChange  : self.bindCallback(self, self.showCoords),
        onSelect  : self.bindCallback(self, self.showCoords),
        setSelect : select
      });

      $('#mapCropMessage').show();
    },

    initJzoom: function() {
      var self = this;

      this.destroyJcrop();
      this.resetJbbox();
      this.vars.jCropType = "zoom";
      this.vars.jzoomAPI = $.Jcrop('#' + self.vars.mapOutputImage.attr("id"), {
        addClass      : "customJzoom",
        bgOpacity     : 1,
        bgColor       : "white",
        onChange      : self.bindCallback(self, self.showCoords),
        onSelect      : self.bindCallback(self, self.showCoords)
      });

      $('.jcrop-tracker').mousedown(function() { self.activateJcrop('zoom'); });
    },

    initJquery: function() {
      var self = this;

      this.destroyJcrop();
      this.resetJbbox();
      this.vars.jCropType = "query";

      this.vars.jqueryAPI = $.Jcrop('#' + self.vars.mapOutputImage.attr("id"), {
        addClass      : "customJzoom",
        bgOpacity     : 1,
        bgColor       :'white',
        onChange      : self.bindCallback(self, self.showCoords),
        onSelect      : self.bindCallback(self, self.showCoords)
      });

      $('.jcrop-tracker').mousedown(function() { self.activateJcrop('query'); });
    },

    activateJcrop: function(type) {
      switch(type) {
        case 'zoom':
          $(document).bind("mouseup", this, this.aZoom);
        break;

        case 'query':
          $(document).bind("mouseup", this, this.aQuery);
        break;
      }
    },

    aZoom: function(event) {
      var self = event.data;
      self.destroyRedo();
      self.showMap();
      $(document).unbind("mouseup", self.aZoom);
    },

    dblclickZoom: function(obj, e) {
      var x = 0, y = 0, pos = {};

      pos = $(obj).offset();
      x   = (e.pageX - pos.left);
      y   = (e.pageY - pos.top);

      $('#bbox_rubberband').val(x+','+y+','+x+','+y);
      this.destroyRedo();
      this.showMap();
    },

    aQuery: function(e) {
      var self      = e.data,
          fillColor = self.vars.fillColor.r + " " + self.vars.fillColor.g + " " + self.vars.fillColor.b,
          formData  = {
            bbox           : $('#rendered_bbox').val(),
            bbox_query     : $('#bbox_query').val(),
            projection     : $('#projection').val(),
            projection_map : $('#projection_map').val(),
            origin         : $('#origin').val(),
            qlayer         : ($('#stateprovinces').prop('checked')) ? 'stateprovinces_polygon' : 'countries',
            width          : $('#width').val(),
            height         : $('#height').val()
          };

      $(document).unbind("mouseup", self.aQuery);

      self.destroyJcrop();
      self.destroyRedo();
      self.showSpinner();

      $.ajax({
        type    : 'POST',
        url     : self.settings.baseUrl + '/query/',
        data    : formData,
        timeout : 30000,
        success : function(data) {
          if(data.length > 0) {
            var regions = data.map(function(e) { return e; }).join(", "),
                fieldsets = self.vars.fieldSetsRegions.find('div.fieldset-regions'),
                num_fieldsets = fieldsets.length;

            $.each(fieldsets, function(i) {
              if(i === (num_fieldsets-1) && !self.vars.fieldSetsRegions.find('button[data-type="regions"]').prop('disabled')) {
                self.addAccordionPanel('regions');
                num_fieldsets += 1;
              }
              if(self.vars.fieldSetsRegions.find('input[name="regions['+i+'][title]"]').val() === "" || self.vars.fieldSetsRegions.find('textarea[name="regions['+i+'][data]"]').val() === "") {
                self.vars.fieldSetsRegions.find('input[name="regions['+i+'][title]"]').val("Selected Region " + (i+1).toString());
                self.vars.fieldSetsRegions.find('input[name="regions['+i+'][color]"]').val(fillColor);
                self.vars.fieldSetsRegions.find('input[name="regions['+i+'][hatch]"]').prop("checked", false);
                self.vars.fieldSetsRegions.find('input[name="regions['+i+'][border]"]').prop("checked", true);
                self.vars.fieldSetsRegions.find('textarea[name="regions['+i+'][data]"]').val(regions);
                if(i > 0) { self.vars.fieldSetsRegions.accordion({active: i}); }
                return false;
              }
            });

            self.showMap();
          } else {
            self.hideSpinner();
          }
        },
        error   : function(xhr, ajaxOptions, thrownError) {
          self.unusedVariables(xhr,thrownError);
          if(ajaxOptions === 'timeout') { self.hideSpinner(); }
        }
      });
    },

    textareaCounter: function(type, action) {
      var vars = this.vars;

      switch(action) {
        case 'get':
          switch(type) {
            case 'coords':
              return vars.newPointCount;
            case 'regions':
              return vars.newRegionCount;
            case 'wkt':
              return vars.newWKTCount;
          }
          break;

        case 'increase':
          switch(type) {
            case 'coords':
              vars.newPointCount += 1;
              return vars.newPointCount;
            case 'regions':
              vars.newRegionCount += 1;
              return vars.newRegionCount;
            case 'wkt':
              vars.newWKTCount += 1;
              return vars.newWKTCount;
          }
          break;

        case 'decrease':
          switch(type) {
            case 'coords':
              vars.newPointCount -= 1;
              return vars.newPointCount;
            case 'regions':
              vars.newRegionCount -= 1;
              return vars.newRegionCount;
            case 'wkt':
              vars.newWKTCount -= 1;
              return vars.newWKTCount;
          }
          break;
      }
    },

    addAccordionPanel: function(data_type) {
      var self     = this,
          counter  = self.textareaCounter(data_type, 'get'),
          button   = $("button.addmore[data-type='" + data_type + "']"),
          clone    = {},
          color    = (data_type === 'coords') ? "0 0 0" : "150 150 150",
          num      = 0,
          children = [];

      if(button.attr("data-type") === data_type) {

        if(counter < self.settings.maxTextareaCount) {
          button.parent().prev().accordion({active: false, heightStyle : "content"});
          clone = button.parent().prev().children("div:last").clone();
          num = parseInt(clone.find("h3 a").text().split(" ")[1],10);
          counter = self.textareaCounter(data_type, 'increase');
          clone.find("h3").attr("id", clone.find("h3").attr("id").replace(/(\d+)+/g, num));
          clone.find("h3 a").text(clone.find("h3 a").text().split(" ")[0] + " " + (num+1).toString());
          clone.find("div").eq(0).attr("id", clone.find("div").eq(0).attr("id").replace(/(\d+)+/g, num));
          clone.find("input.m-mapTitle").attr("name", data_type + "["+num.toString()+"][title]").val("");
          clone.find("textarea")
                  .attr("name", data_type + "["+num.toString()+"][data]")
                  .removeClass("textarea-processed")
                  .val("")
                  .each(function() {
                    $(this).css('resize', 'vertical');
                  });

          clone.find("select.m-mapShape").attr("name", data_type + "["+num.toString()+"][shape]").val("circle");
          clone.find("select.m-mapSize").attr("name", data_type + "["+num.toString()+"][size]").val("10");
          clone.find("input.colorPicker").attr("name", data_type + "["+num.toString()+"][color]").val(color).ColorPicker({
            onBeforeShow: function() {
              color = $(this).val().split(" ");
              $(this).ColorPickerSetColor(self.RGBtoHex(color[0], color[1], color[2]));
            },
            onHide: function(colpkr) {
              $(colpkr).hide();
              return false;
            },
            onSubmit: function(hsb, hex, rgb, el) {
              self.unusedVariables(hsb,hex);
              $(el).val(rgb.r + " " + rgb.g + " " + rgb.b);
              $(el).ColorPickerHide();
            }
          }).on('keyup', function() {
            color = $(this).val().split(" ");
            $(this).ColorPickerSetColor(self.RGBtoHex(color[0], color[1], color[2]));
          });
          clone.find("input.m-mapShadow").attr("name", data_type + "["+num.toString()+"][shadow]").prop("checked", false);
          clone.find("input.m-mapBorder").attr("name", data_type + "["+num.toString()+"][border]").prop("checked", true);
          clone.find("input.m-mapHatch").attr("name", data_type + "["+num.toString()+"][hatch]").prop("checked", false);

          children = button.parent().prev().append(clone).children("div");
          self.associateGroupLabels();

          children.each(function(i, val) {
            self.unusedVariables(val);
            if (i === children.length-1) {
              $(this).find("button.removemore").show().on('click', function(e) {
                e.preventDefault();
                self.removeAccordionPanel(clone, data_type);
                counter = self.textareaCounter(data_type, 'decrease');
              }).parent()
              .find("button.clearself").on('click', function(e) {
                e.preventDefault();
                self.clearZone($(this));
              }).parent().parent()
              .find(".ui-icon:last").remove();
            }
          });

          button.parent().prev().accordion("destroy").accordion({
            header      : 'h3',
            collapsible : true,
            autoHeight  : false,
            active      : false,
            heightStyle : "content"
          });

          if(data_type === 'regions') { self.bindAutocomplete(); }

        }

        self.associateGroupLabels();
        if(counter >= self.settings.maxTextareaCount-3) {
          button.prop("disabled", true);
        }

      }
    },

    removeAccordionPanel: function(clone, data_type) {
      var button = $("button.addmore[data-type='" + data_type + "']");

      clone.nextAll().each(function() {
        var num = parseInt($(this).find("h3 a").text().split(" ")[1],10),
        name_prefix = data_type + "["+(num-2).toString()+"]";

        $(this).find("h3 a").text($(this).find("h3 a").text().split(" ")[0] + " " + (num-1).toString());
        $(this).find("input.m-mapTitle").attr("name", name_prefix + "[title]");
        $(this).find("textarea").attr("name", name_prefix + "[data]");
        $(this).find("select.m-mapShape").attr("name", name_prefix + "[shape]");
        $(this).find("select.m-mapSize").attr("name", name_prefix + "[size]");
        $(this).find("input.colorPicker").attr("name", name_prefix + "[color]");
        $(this).find("input.m-mapShadow").attr("name", name_prefix + "[shadow]");
        $(this).find("input.m-mapBorder").attr("name", name_prefix + "[border]");
        $(this).find("input.m-mapHatch").attr("name", name_prefix + "[hatch]");
      });
      clone.remove();
      this.associateGroupLabels();
      button.prop("disabled", false);
    },


    bindAddButtons: function() {
      var self = this;

      $('#map-points, #map-regions, #map-wkt').on('click', 'button.addmore', function(e) {
        var data_type = $(this).attr("data-type"), fieldsets = 0;
        e.preventDefault();
        self.addAccordionPanel(data_type);
        fieldsets = $(this).parent().prev().children().length;
        $(this).parent().prev().accordion({active: fieldsets-1});
        return false;
      });
    },

    loadMapList: function(object) {
      var self  = this,
          obj = object || {},
          data = {};

      $('#usermaps').html('');

      self.showSpinner();

      data = {
        locale : self.getParameterByName("locale"),
        search : (obj.search) ? obj.search.toLowerCase() : null,
        uid    : obj.uid || null
      };

      if(obj.sort) {
        data.sort = obj.sort.item;
        data.dir = obj.sort.dir;
      }

      if(!data.locale) { delete data.locale; }
      if(!data.search) { delete data.search; }
      if(!data.uid) { delete data.uid; }

      $.ajax({
        type     : 'GET',
        url      : self.settings.baseUrl + "/usermap/",
        data     : data,
        dataType : 'html',
        success  : function(response) {
          if(response.indexOf("session timeout") !== -1) {
            window.location.reload();
          } else {
            $('#usermaps').off().html(response)
              .on('click', 'a.toolsRefresh', function(e) { e.preventDefault(); self.loadMapList(); })
              .on('click', 'a.ui-icon-triangle-sort', function(e) {
                e.preventDefault();
                data.sort = { item : $(this).attr("data-sort"), dir : "asc" };
                if($(this).hasClass("asc")) { data.sort.dir = "desc"; }
                self.loadMapList(data);

              })
              .on('click', 'a.map-load', function(e) {
                e.preventDefault();
                self.loadMap(this);

              })
              .on('click', 'a.map-share', function(e) {
                e.preventDefault();
                self.shareMap(this, 'create', data);

              })
              .on('click', 'a.map-unshare', function(e) {
                e.preventDefault();
                self.shareMap(this, 'destroy', data);

              })
              .on('click', 'a.map-delete', function(e) {
                e.preventDefault();
                self.deleteMapConfirmation(this);
            });
            $('#filter-mymaps')
              .val(obj.search)
              .on('keypress', function(e) {
                var key = e.keyCode || e.which;
                if(key === 13) {
                  e.preventDefault();
                  data.search = $(this).val();
                  self.loadMapList(data);

                }
              }).focus();
            self.hideSpinner();
          }
        }
      });
    },

    loadShareList: function(object) {
      var self = this,
          obj = object || {},
          data = {};

      self.showSpinner();

      data = {
        locale : self.getParameterByName("locale")
      };

      if(obj.sort) {
        data.sort = obj.sort.item;
        data.dir = obj.sort.dir;
      }

      if(!data.locale) { delete data.locale; }

      $.ajax({
        type     : 'GET',
        url      : self.settings.baseUrl + "/share/",
        data     : data,
        dataType : 'html',
        success  : function(response) {
          if(response.indexOf("session timeout") !== -1) {
            window.location.reload();
          } else {
            $('#sharedmaps').off().html(response)
              .on('click', 'a.ui-icon-triangle-sort', function(e) {
                e.preventDefault();
                data.sort = { item : $(this).attr("data-sort"), dir : "asc" };
                if($(this).hasClass("asc")) { data.sort.dir = "desc"; }
                self.loadShareList(data);

              })
              .on('click', 'a.map-load', function(e) {
                e.preventDefault();
                self.loadMap(this);

              });
            self.hideSpinner();
          }
        }
      });
    },

    shareMap: function(obj, type, data) {
      var self = this,
          mid = $(obj).attr("data-id");

      if(type === 'create') {
        self.showSpinner();
        $.ajax({
          type     : 'POST',
          url      : self.settings.baseUrl + "/share/",
          data     : { "mid" : mid },
          dataType : "json",
          success  : function(response) {
            if (response.url && $('#actionsBar a.toolsEmbed').attr('data-id') === mid) { self.activateEmbed(mid, response.url); }
            if (type === 'destroy' && $('#actionsBar a.toolsEmbed').attr('data-id') === mid) { self.activateEmbed(mid, null); }
            self.loadMapList(data);
            self.loadShareList();
            self.hideSpinner();
          }
        });
      } else if (type === 'destroy') {
        self.showSpinner();
        $.ajax({
          type     : 'DELETE',
          url      : self.settings.baseUrl + "/share/" + mid,
          dataType : "json",
          success  : function(response) {
            if (response.url && $('#actionsBar a.toolsEmbed').attr('data-id') === mid) { self.activateEmbed(mid, response.url); }
            if (type === 'destroy' && $('#actionsBar a.toolsEmbed').attr('data-id') === mid) { self.activateEmbed(mid, null); }
            self.loadMapList(data);
            self.loadShareList();
            self.hideSpinner();
          }
        });
      }

    },

    removeExtraElements: function() {
      var self = this;

      $.each(this.vars.fieldSetsPoints.find('.fieldset-points'), function(i) {
        if(i > 2) { $(this).remove(); }
      });

      $.each(this.vars.fieldSetsRegions.find('.fieldset-regions'), function(i) {
        if(i > 2) { $(this).remove(); }
      });

      $.each(this.vars.fieldSetsWKT.find('.fieldset-wkt'), function(i) {
        if(i > 2) { $(this).remove(); }
      });

      self.vars.newPointCount = 0;
      self.vars.newRegionCount = 0;
      self.vars.newWKTCount = 0;
    },

    prepareInputs: function(data) {
      return {status: 'ok', map: window.SimpleMapprDocument.normalize(data, this.settings.maxTextareaCount)};
    },

    loadInputs: function(data) {
      data = this.prepareInputs(data);
      var self = this,
          filter = $('#filter-mymaps').val();

      this.removeExtraElements();
      $('#form-mapper').find('input:not([type=button],[type=submit],[type=hidden],[type=file]), textarea').each(function() {
        if (this.type === 'checkbox' || this.type === 'radio') { this.checked = false; }
        else { this.value = ''; }
      });
      $.each(['width', 'height'], function(key, value) {
        self.unusedVariables(key);
        $('#'+value).val($('#'+value).val());
      });
      $('#map-points, #map-regions, #map-wkt').find('button.addmore').prop("disabled", false);
      $('#filter-mymaps').val(filter);
      $('#origin-selector').hide();
      this.loadCoordinates(data);
      this.loadRegions(data);
      this.loadWKT(data);
      this.loadLayers(data);
      this.loadSettings(data);
      this.associateGroupLabels();
    },

    loadMap: function(obj) {
      var self     = this,
          id       = $(obj).attr("data-id");

      this.tabSelector(0);
      this.showSpinner();

      $.ajax({
        type     : 'GET',
        url      : self.settings.baseUrl + "/usermap/" + id +".json",
        dataType : 'json',
        timeout  : 30000,
        success  : function(data) {
          self.hideSpinner();
          if(data.status === 'ok') {
            self.loadInputs(data);
            self.showMap(data);
            self.bindStorage();
            self.activateEmbed(id, data.share_url);
          } else {
            self.showErrorMessage($('#mapper-loading-error-message').text());
          }
          self.toggleUndo();
          self.toggleRedo();
        },
        error: function(xhr) { self.hideSpinner(); self.showErrorMessage((xhr.responseJSON && xhr.responseJSON.error) || $('#mapper-loading-error-message').text()); }
      });
    },

    loadSettings: function(data) {
      var pattern           = /[?*:;{}\\ "']+/g,
          map_title         = "",
          download_filetype = "",
          self              = this;

      map_title = data.map.save.title;

      $('#save\\[title\\]').val(map_title);
      $('#m-mapSaveTitle').val(map_title);

      $('#mapTitle').text(map_title);

      map_title = map_title.replace(pattern, "_");
      $('#file-name').val(map_title);
      $("#projection").val(data.map.projection);
      if(self.vars.origins.hasOwnProperty($("#projection").val())) { $('#origin-selector').show(); }
      $.each(['bbox_map', 'projection_map', 'origin_map', 'rotation', 'origin', 'width', 'height'], function(key, value) {
        self.unusedVariables(key);
        $('#'+value).val((value === 'origin' || value === 'origin_map') && data.map[value] === false ? '' : data.map[value]);
      });
      if(data.map.origin === undefined || data.map.origin === null || data.map.origin === '' || data.map.origin === false) { $('#origin').val(self.vars.origins[$("#projection").val()]); }

      $('#border_thickness').val(1.25);
      $('#border-slider').slider({value:1.25});
      if(data.map.border_thickness !== undefined && data.map.border_thickness) {
        $('#border_thickness').val(data.map.border_thickness);
        $('#border-slider').slider({value:data.map.border_thickness});
      }

      self.setRotation(data.map.rotation);
      self.resetJbbox();

      $.each(["border", "legend", "scalebar", "scalelinethickness"], function(key, value) {
        self.unusedVariables(key);
        $('#'+value).prop('checked', false);
        $('#options\\['+value+'\\]').val("");
      });

      if(data.map.options !== undefined) {
        $.each(["border", "legend", "scalebar", "scalelinethickness"], function(key, value) {
          self.unusedVariables(key);
          if(window.SimpleMapprDocument.enabled(data.map.options[value])) {
            $('#'+value).prop('checked', true);
            $('#options\\['+value+'\\]').val(1);
          }
        });
      }

      if(data.map.download_factor !== undefined && data.map.download_factor) {
        $('#download_factor').val(data.map.download_factor);
        $('#download-factor-' + data.map.download_factor).prop('checked', true);
      } else {
        $('#download-factor-3').prop('checked', true);
      }

      if(data.map.download_filetype !== undefined && data.map.download_filetype) {
        $('#download_filetype').val(data.map.download_filetype);
        download_filetype = $('#download-' + data.map.download_filetype).prop('checked', true);
        self.toggleFileType(download_filetype);
      } else {
        $('#download-svg').prop('checked', true);
      }

      var spacing = data.map.gridspace !== undefined ? data.map.gridspace : data.map.grid_space;
      if(spacing !== undefined && spacing !== '') {
        $('#gridspace-' + spacing).prop('checked', true);
      } else {
        $('#gridspace').prop('checked', true);
      }

      if(window.SimpleMapprDocument.enabled(data.map.hide_gridlabel)) {
        $('#gridlabel').prop('checked', true);
      } else {
        $('#gridlabel').prop('checked', false);
      }
    },

    loadCropSettings: function(data) {
      var rubberband = [];

      if(data.map.bbox_rubberband) {
        rubberband = data.map.bbox_rubberband.split(",");
        this.initJcrop([rubberband[2], rubberband[3], rubberband[0], rubberband[1]]);
        this.toggleFileFactor(data.map.download_factor);
      } else {
        this.destroyJcrop();
      }
    },

    loadShapeSize: function(i, coords) {
      var fields = {shape: 'circle', size: '10'}, self = this;
      Object.keys(fields).forEach(function(field) {
        var value = coords[i][field];
        self.vars.fieldSetsPoints.find('select[name="coords[' + i + '][' + field + ']"]').val(value === undefined || value === null || value === '' ? fields[field] : value);
      });
    },

    loadCoordinates: function(data) {
      var self        = this,
          coords      = data.map.coords || [],
          coord_title = "",
          coord_data  = "",
          coord_color = "",
          coord_shadow = false;

      if (coords.length > self.settings.maxTextareaCount) {
        self.showTooMuchData();
        return false;
      } else {
        $.each(coords, function(i) {
          if(i > 2) { self.addAccordionPanel('coords'); }

          coord_title = coords[i].title || "";
          coord_data  = coords[i].data || "";
          coord_color = coords[i].color || "0 0 0";
          coord_shadow = window.SimpleMapprDocument.enabled(coords[i].shadow);

          self.vars.fieldSetsPoints.find('input[name="coords['+i.toString()+'][title]"]').val(coord_title);
          self.vars.fieldSetsPoints.find('textarea[name="coords['+i.toString()+'][data]"]').val(coord_data);
          self.loadShapeSize(i, coords);
          self.vars.fieldSetsPoints.find('input[name="coords['+i.toString()+'][color]"]').val(coord_color);
          self.vars.fieldSetsPoints.find('input[name="coords['+i.toString()+'][shadow]"]').prop("checked", coord_shadow);
        });
        return true;
      }
    },

    loadWKT: function(data) {
      var self      = this,
          wkt       = data.map.wkt || [],
          wkt_title = "",
          wkt_data  = "",
          wkt_color = "",
          wkt_border = false,
          wkt_hatch = false;

      $.each(wkt, function(i) {
        wkt_border = false;
        wkt_hatch = false;
        if(i > 2) { self.addAccordionPanel('wkt'); }

        wkt_title = wkt[i].title || "";
        wkt_data  = wkt[i].data  || "";
        wkt_color = wkt[i].color || "";
        if (window.SimpleMapprDocument.enabled(wkt[i].border)) {
          wkt_border = true;
        }
        if (window.SimpleMapprDocument.enabled(wkt[i].hatch)) {
          wkt_hatch = true;
        }

        self.vars.fieldSetsWKT.find('input[name="wkt['+i.toString()+'][title]"]').val(wkt_title);
        self.vars.fieldSetsWKT.find('textarea[name="wkt['+i.toString()+'][data]"]').val(wkt_data);
        self.vars.fieldSetsWKT.find('input[name="wkt['+i.toString()+'][color]"]').val(wkt_color);
        self.vars.fieldSetsWKT.find('input[name="wkt['+i.toString()+'][border]"]').prop("checked", wkt_border);
        self.vars.fieldSetsWKT.find('input[name="wkt['+i.toString()+'][hatch]"]').prop("checked", wkt_hatch);
      });
    },

    loadRegions: function(data) {
      var self         = this,
          regions      = data.map.regions || [],
          region_title = "",
          region_data  = "",
          region_color = "",
          region_border = false,
          region_hatch = false;

      $.each(regions, function(i) {
        region_border = false;
        region_hatch = false;
        if(i > 2) { self.addAccordionPanel('regions'); }

        region_title = regions[i].title || "";
        region_data  = regions[i].data  || "";
        region_color = regions[i].color || "";

        if (window.SimpleMapprDocument.enabled(regions[i].border)) {
          region_border = true;
        }
        if (window.SimpleMapprDocument.enabled(regions[i].hatch)) {
          region_hatch = true;
        }

        self.vars.fieldSetsRegions.find('input[name="regions['+i.toString()+'][title]"]').val(region_title);
        self.vars.fieldSetsRegions.find('textarea[name="regions['+i.toString()+'][data]"]').val(region_data);
        self.vars.fieldSetsRegions.find('input[name="regions['+i.toString()+'][color]"]').val(region_color);
        self.vars.fieldSetsRegions.find('input[name="regions['+i.toString()+'][border]"]').prop("checked", region_border);
        self.vars.fieldSetsRegions.find('input[name="regions['+i.toString()+'][hatch]"]').prop("checked", region_hatch);
      });
    },

    loadLayers: function(data) {
      var self = this;
      if(data.map.layers) {
        $.each(data.map.layers, function(key, value) {
          self.unusedVariables(value);
          $('#'+key).prop('checked', window.SimpleMapprDocument.enabled(value));
        });
      }
    },

    activateEmbed: function(mid, shareUrl) {
      var self    = this,
          types   = ['img','kml','svg','json'];

      var embed = $('#actionsBar').find('a.toolsEmbed').attr("data-id", mid).off('click');
      if (!shareUrl) { embed.hide(); return; }
      shareUrl = new URL(shareUrl, window.location.href).href;
      embed.css({display: 'block'}).on('click', function(e) {
        e.preventDefault();
        $.each(types, function(key, value) {
          self.unusedVariables(key);
          if(value === 'img') {
            $('#embed-'+value).val("<img src=\"" + shareUrl + ".png\" alt=\"\" />");
          } else {
            $('#embed-'+value).val(shareUrl + "." + value);
          }
        });

        $('#mapEmbed').find("span.mid").text(mid).end()
                      .dialog({
                        width         : '525',
                        dialogClass   : 'ui-dialog-title-mapEmbed',
                        autoOpen      : true,
                        modal         : true,
                        closeOnEscape : true,
                        draggable     : true,
                        resizable     : false,
                        buttons       : [
                          {
                            "text"  : "OK",
                            "class" : "positive ui-button-text-only",
                            "click" : function() {
                              $(this).dialog("destroy");
                            }
                          }
                        ]
                      });
      });
    },

    deleteMapConfirmation: function(obj) {
      var self    = this,
          id      = $(obj).attr("data-id"),
          message = '<em>' + $(obj).parent().parent().find("td.title").text() + '</em>';

      $('#mapper-message-delete').find('span').html(message).end().dialog({
        height        : '250',
        width         : '500',
        dialogClass   : 'ui-dialog-title-mapper-message-delete',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons       : [
          {
            "text"  : $('#button-titles').find('span.delete').text(),
            "class" : "negative ui-button-text-only",
            "click" : function() {
              $.ajax({
                type    : 'DELETE',
                url     :  self.settings.baseUrl + "/usermap/" + id,
                success : function() {
                  self.loadMapList();

                }
              });
              $(this).dialog("destroy");
            }
          },
          {
            "text"  : $('#button-titles').find('span.cancel').text(),
            "class" : "ui-button-cancel ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
            }
          }]
      });
    },

    getLanguage: function() {
      var param = "", locale = this.getParameterByName("locale");

      if(locale === "fr_FR" || locale === "es_ES") {
        param = "?locale=" + locale;
      }
      return param;
    },

    mapSave: function() {
      var missingTitle = false,
          pattern      = /[?*:;{}\\ "'\/@#!%\^()<>.]+/g,
          map_title    = $('#m-mapSaveTitle'),
          self         = this;

      $('#mapSave').dialog({
        autoOpen      : true,
        height        : '175',
        width         : '365',
        dialogClass   : 'ui-dialog-title-mapSave',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons       : [
          {
            "text"  : $('#button-titles').find('span.save').text(),
            "class" : "positive ui-button-text-only",
            "click" : function() {
              missingTitle = $.trim(map_title.val()) === '';
              if(missingTitle) {
                map_title.addClass('ui-state-error').on('keyup', function() {
                  $(this).removeClass('ui-state-error');
                });
              } else {
                $('#save\\[title\\]').val(map_title.val());
                $.each(['factor', 'filetype'], function(key, value) {
                  self.unusedVariables(key);
                  $('#download_'+value).val($('#mapExport').find('input[name="download-'+value+'"]:checked').val());
                });
                $('#grid_space').val($('#graticules-selection').find('input[name="gridspace"]:checked').val());

                self.setFormOptions();
                self.showSpinner();

                if(self.vars.jcropAPI === undefined) { $('#bbox_rubberband').val(''); }

                $.ajax({
                  type        : 'POST',
                  url         : self.settings.baseUrl + '/usermap/',
                  data        : $("#form-mapper").serialize(),
                  dataType    : 'json',
                  timeout     : 30000,
                  error       : function(xhr) { self.hideSpinner(); self.showErrorMessage((xhr.responseJSON && xhr.responseJSON.error) || $('#mapper-loading-error-message').text()); },
                  success     : function(data) {
                    $('#mapTitle').text(map_title.val());
                    $('#file-name').val(map_title.val().replace(pattern, "_"));
                    self.activateEmbed(data.mid, data.share_url);
                    self.loadMapList();
                    self.hideSpinner();

                  }
                });

                $(this).dialog("destroy");
              }
            }
        },
        {
          "text"  : $('#button-titles').find('span.cancel').text(),
          "class" : "ui-button-cancel ui-button-text-only",
          "click" : function() {
            $(this).dialog("destroy");
          }
        }]
      });
    },

    mapDownload: function() {
      var self = this;

      $('#mapExport').dialog({
        autoOpen      : true,
        width         : '620',
        dialogClass   : 'ui-dialog-title-mapExport',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons       : [
          {
            "text"  : $('#button-titles').find('span.download').text(),
            "class" : "positive ui-button-text-only",
            "click"   : function() {
              self.generateDownload();
            }
          },
          {
            "text"  : $('#button-titles').find('span.cancel').text(),
            "class" : "ui-button-cancel ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
            }
          }]
      });
    },

    bindSubmit: function() {
      var self = this;

      $('#map-points, #map-regions, #map-wkt').find('button.submitForm').on('click', function(e) {
        e.preventDefault();
        if(!self.missingFieldSetTitle()) {
          self.destroyRedo();
          self.showMap();
          self.tabSelector(0);
        }
      });
    },

    missingFieldSetTitle: function() {
      var self = this,
          fieldsets = $.makeArray(this.vars.fieldSetsPoints.children())
                      .concat($.makeArray(this.vars.fieldSetsRegions.children()))
                      .concat($.makeArray(this.vars.fieldSetsWKT.children())),
          title = "",
          missingTitle = false,
          fieldset = "",
          tab = 1;

      $.each(fieldsets, function() {
        fieldset = $(this);
        title = fieldset.find('input.m-mapTitle').on('keyup', function() {
          $(this).removeClass('ui-state-error');
        });
        if(fieldset.find('textarea.m-mapCoord').val() && title.val() === '') {
          missingTitle = true;
          title.addClass('ui-state-error');
          if(fieldset.parent().attr("id") === self.vars.fieldSetsWKT.attr("id")) { tab = 2; }
          if(fieldset.parent().attr("id") === self.vars.fieldSetsRegions.attr("id")) { tab = 3; }
          self.tabSelector(tab);
          self.showMessage($('#mapper-missing-legend').text());
          return false;
        }
      });
      return missingTitle;
    },

    mapToggleSettings: function() {
      $('#mapToolsCollapse').find('a').trigger('click');
    },

    bindPanelToggle: function() {
      var self = this;
      $('#mapToolsCollapse').find('a').on('click', function(event) {
        event.preventDefault();
        if (self.missingFieldSetTitle()) { return; }
        var collapsed = $(this).parent().toggleClass('mapTools-collapsed').hasClass('mapTools-collapsed');
        $(this).attr('aria-expanded', String(!collapsed));
        $('#mapTools').toggle(!collapsed);
        var width = collapsed ? Math.max(900, Math.floor($(window).width() * 0.98)) : 900;
        $('#actionsBar').width(width + 10); $('#map').width(width); $('#width').val(width);
        self.mapRefresh();
      });
    },

    showMessage: function(message) {

      $('#mapper-message').html(message).dialog({
        autoOpen      : true,
        height        : '200',
        width         : '400',
        dialogClass   : 'ui-dialog-title-mapper-message',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons       : [
          {
            "text"  : "OK",
            "class" : "positive ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
            }
          }
        ]
      }).show();
    },

    drawLegend: function() {
      var legend_url = $('#legend_url').val(), legend = $('#mapLegend');

      if(legend_url) {
        legend.html("<img src=\"" + legend_url + "\" />");
      } else {
        legend.html('<p><em>' + $('#mapper-legend-message').text() + '</em></p>');
      }
    },

    drawScalebar: function() {
      $('#mapScale').find('img').attr('src', $('#scalebar_url').val()).show();
    },

    showBadPoints: function() {
      var bad_points = $('#bad_points').val();

      if(bad_points) {
        $('#badRecords').html(bad_points);
        $('#badRecordsWarning').show();
      }
    },

    showBadDrawings: function() {
      var bad_drawings = $('#bad_drawings').val(),
          bad_points = $('#bad_points').val();

      if(bad_drawings) {
        $('#badRecords').html([bad_points,bad_drawings].filter(String).join("<br />"));
        $('#badRecordsWarning').show();
      }
    },

    showSpinner: function() {
      this.vars.spinner.show(); this.vars.mapOutput.attr('aria-busy', 'true');
    },

    hideSpinner: function() {
      this.vars.spinner.hide(); this.vars.mapOutput.attr('aria-busy', 'false');
    },

    showErrorMessage: function(content) {
      this.hideErrorMessage();
      $('<span>', { 'class': 'mapper-message-error ui-widget-content', role: 'alert' }).text(content).appendTo(this.vars.mapOutput);
    },

    hideErrorMessage: function() {
      this.vars.mapOutput.find('.mapper-message-error').remove();
    },

    showMap: function(load_data) {
      var self         = this,
          token        = new Date().getTime(),
          formString   = "",
          formObj      = {};

      self.destroyJcrop();

      $('#output').val('png');        // set the preview and output values
      $('#badRecordsWarning').hide();  // hide the bad records warning
      $('#download_token').val(token);

      self.showSpinner();
      formString = $("#form-mapper").serialize();
      formObj    = self.readDocument();
      self.postData(formString, load_data);
      window.SimpleMapprStorage.set("do-" + token.toString(), formObj);
      self.toggleUndo(true);
    },

    postData: function(formData, load_data) {
      var self = this, sequence = ++self.vars.renderSequence;
      if (self.vars.renderRequest) { self.vars.renderRequest.abort(); }
      self.hideErrorMessage();
      self.vars.renderRequest = $.ajax({
        type: 'POST', url: self.settings.baseUrl + '/application.json', data: formData, dataType: 'json', timeout: 30000,
        success: function(data) {
          if (sequence !== self.vars.renderSequence) { return; }
          if (!data || !Array.isArray(data.size) || !data.mapOutputImage) {
            self.hideSpinner(); self.showErrorMessage($('#mapper-loading-error-message').text()); return;
          }
          self.resetFormValues(data);
          window.SimpleMapprStorage.current(self.readDocument());
          self.resetJbbox(); self.drawMap(data, load_data);
          self.drawLegend(); self.drawScalebar(); self.showBadPoints(); self.showBadDrawings(); self.addBadRecordsViewer();
        },
        error: function(xhr, status) {
          if (status === 'abort' || sequence !== self.vars.renderSequence) { return; }
          self.hideSpinner();
          self.showErrorMessage((xhr.responseJSON && xhr.responseJSON.error) || $('#mapper-loading-error-message').text());
        }
      });
    },

    resetFormValues: function(data) {
      var ele = ["rendered_bbox", "rendered_rotation", "rendered_projection", "legend_url", "scalebar_url", "bad_points", "bad_drawings"];

      $.each(this.vars.mapOutput.find('input'), function() { $(this).val(''); });
      $.each(ele, function() { $('#' + this).val(data[this]); });
      $('#bbox_map').val($('#rendered_bbox').val());
      $('#projection_map').val($('#rendered_projection').val());
      $('#origin_map').val(data.rendered_origin == null || data.rendered_origin === false ? '' : data.rendered_origin);
      $('#rotation').val($('#rendered_rotation').val());
      $('#pan').val('');
    },

    drawMap: function(data, load_data) {
      var self = this, sequence = self.vars.renderSequence;
      this.vars.mapOutputImage.off('.simplemapprRender')
        .attr('width', data.size[0]).attr('height', data.size[1])
        .css({width: data.size[0] + 'px', height: data.size[1] + 'px'})
        .one('load.simplemapprRender', function() {
          if (sequence !== self.vars.renderSequence) { return; }
          self.loadCropSettings(load_data || {map: {bbox_rubberband: ''}}); self.hideSpinner();
        })
        .one('error.simplemapprRender', function() {
          if (sequence !== self.vars.renderSequence) { return; }
          self.hideSpinner(); self.showErrorMessage($('#mapper-loading-error-message').text());
        }).attr('src', data.mapOutputImage);
    },

    addBadRecordsViewer: function() {
      var self = this;

      $('#badRecordsViewer').dialog({
        autoOpen      : false,
        height        : '200',
        width         : '500',
        dialogClass   : 'ui-dialog-title-badRecordsViewer',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons: [
          {
            "text"  : "OK",
            "class" : "positive ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
              $('#badRecords').hide();
            }
          }
        ]
      });

      $('#badRecordsWarning').find('a.toolsBadRecords').on('click', function(e) {
        e.preventDefault();
        self.addBadRecordsViewer();
        $('#badRecordsViewer').dialog("open");
        $('#badRecords').show();
      });
    },

    generateDownload: function() {
      var self        = this,
          pattern     = /[~$?*,:;{}\[\]\\ "'\/@#!%\^()<>.+=|`&]+/g,
          map_title   = $('#file-name').val(),
          token       = new Date().getTime().toString(),
          formData    = "",
          filetype    = "png";

      map_title = map_title.replace(pattern, "_");
      $('#file-name').val(map_title);
      $('#file_name').val(map_title);

      $('#download_factor').val($('#mapExport').find('input[name="download-factor"]:checked').val());

      filetype = $('#mapExport').find('input[name="download-filetype"]:checked').val();

      self.setFormOptions();
      $('#download_token').val(token);

      $('#mapExport').find('div.download-dialog').hide().end().next().hide().end().find('div.download-message').show();

      switch(filetype) {
        case 'pptx':
          $('#output').val('pptx');
          if(self.vars.jcropAPI) { $('#crop').val(1); } else { self.resetJbbox(); }
          formData = $("#form-mapper").serialize();
          self.downloadFile(self.settings.baseUrl + "/pptx" + self.getLanguage(), formData, filetype);
          $('#output').val('png');
        break;

        case 'docx':
          $('#output').val('docx');
          if(self.vars.jcropAPI) { $('#crop').val(1); } else { self.resetJbbox(); }
          formData = $("#form-mapper").serialize();
          self.downloadFile(self.settings.baseUrl + "/docx" + self.getLanguage(), formData, filetype);
          $('#output').val('png');
        break;

        case 'kml':
          formData = $("#form-mapper").serialize();
          self.downloadFile(self.settings.baseUrl + "/kml", formData, filetype);
        break;

        default:
          $('#download').val(1);
          $('#output').val(filetype);
          if(self.vars.jcropAPI) { $('#crop').val(1); } else { self.resetJbbox(); }
          formData = $("#form-mapper").serialize();
          self.downloadFile(self.settings.baseUrl + "/application", formData, filetype);
          $('#download').val('');
          $('#output').val('png');
      }


    },

    downloadFile: function(url, formData, filetype) {
      this.hideErrorMessage();
      var self = this, controller = new window.AbortController();
      var timeout = window.setTimeout(function() { controller.abort(); }, self.settings.downloadTimeout);
      return window.fetch(url, {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-Token': self.settings.csrfToken},
        body: formData
      }).then(function(response) {
        if (!response.ok) { throw new Error('download'); }
        return response.blob();
      }).then(function(blob) {
        var href = window.URL.createObjectURL(blob), link = document.createElement('a');
        link.href = href; link.download = ($('#file-name').val() || 'map') + '.' + (filetype === 'pnga' ? 'png' : filetype);
        document.body.appendChild(link); link.click(); link.remove();
        window.setTimeout(function() { window.URL.revokeObjectURL(href); }, 60000);
      }).catch(function() {
        self.showErrorMessage($('#mapper-download-error-message').text() || $('#mapper-loading-error-message').text());
      }).finally(function() { window.clearTimeout(timeout); self.finishDownload(); });
    },

    setFormOptions: function() {
      var self = this;

      $.each(["border", "legend", "scalebar", "scalelinethickness"], function(key, value) {
        self.unusedVariables(key);
        $('#options\\['+value+'\\]').val("");
        if($('#'+value).prop('checked')) {
          $('#options\\['+value+'\\]').val(1);
        }
      });
    },

    finishDownload: function() {
      $('#mapExport').find('div.download-message').hide().end().next().show().end().find('div.download-dialog').show();

    },

    showExamples: function() {
      var message = '<img src="public/images/help-data.png" alt="" />';

      $('#mapper-message-help').html(message).dialog({
        height        : '355',
        width         : '525',
        dialogClass   : 'ui-dialog-title-mapper-message-help',
        autoOpen      : true,
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons       : [
          {
            "text"  : "OK",
            "class" : "positive ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
            }
          }
        ]
      });
    },

    showCodes: function() {
      var data  = (this.getParameterByName("locale")) ? { locale : this.getParameterByName("locale") } : {},
           messageCodes = $('#mapper-message-codes');

      messageCodes.dialog({
        height        : '450',
        width         : '850',
        dialogClass   : 'ui-dialog-title-mapper-message-codes',
        autoOpen      : true,
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons       : [
          {
            "text"  : "OK",
            "class" : "positive ui-button-text-only",
            "click" : function() {
              messageCodes.find('table').remove();
              $(this).dialog("destroy");
            }
          }
        ]
      });

      this.loadCodes(messageCodes, data);
    },

    loadCodes: function(elem, data) {
      var self = this,
          filter = "";
      elem.find("tbody tr td").text("\xa0");
      elem.find("tbody tr td:first").text("\xa0\xa0\xa0").addClass("loading");
      $.ajax({
        type     : 'GET',
        url      : self.settings.baseUrl + '/places/',
        data     : data,
        dataType : 'html',
        success  : function(response) {
          elem.html(response);
          filter = elem.find('input.filter-countries');
          filter.val("");
          if(data.filter !== undefined) { filter.val(data.filter); }
          filter.on('keypress', function(e) {
            var key = e.keyCode || e.which;
            if(key === 13) {
              e.preventDefault();
              data.filter = filter.val();
              self.loadCodes(elem, data);
            }
          }).on('blur', function() {
            data.filter = filter.val();
            self.loadCodes(elem, data);
          });
        }
      });
    },

    performRotation: function(element) {
      $('#rotation').val($(element).attr("data-rotate"));
      this.resetJbbox();
      this.destroyRedo();
      this.showMap();

    },

    setRotation: function(angle) {
      var control  = $('#mapControls'),
          thumb    = control.find('div.thumb'),
          overview = control.find('ul.overview'),
          dots     = overview.find("li"),
          rads     = 0,
          left     = 0,
          top      = 0;

      if(!angle) { angle = 0; }

      angle = parseFloat(angle) < 0 ? parseFloat(angle) +360 : parseFloat(angle);
      $('#rotation').val(angle);
      thumb.attr('aria-valuenow', angle);
      rads = angle * (Math.PI/180);

      overview.css({left: -(angle / 360 * ((dots.outerWidth(true) * (dots.length)))) + 'px'});
      top = Math.round(-Math.cos(rads) * 28 + (control.outerHeight() /2 - thumb.outerHeight() /2)) + 'px';
      left = Math.round(Math.sin(rads) * 28 + (control.outerWidth() /2 - thumb.outerWidth() /2)) + 'px';
      thumb.css({top:top,left:left});
    },

    getParameterByName: function(name) {
      var cname   = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]"),
          regexS  = "[\\?&]" + cname + "=([^&#]*)",
          regex   = new RegExp(regexS),
          results = regex.exec(window.location.href);

      if(results === null) { return ""; }
      return decodeURIComponent(results[1].replace(/\+/g, " "));
    },

    mapCircleSlider: function() {
      var i = 0, output = "";

      for(i = 0; i < 360; i += 5) {
        output += '<li data-rotate="' + i + '"></li>';
      }
      return output;
    },

    clearStorage: function() {
      this.destroyRedo();
      window.SimpleMapprStorage.flush();
    },

    readDocument: function() {
      this.setFormOptions();
      return window.SimpleMapprDocument.fromEntries($('#form-mapper').serializeArray(), this.settings.maxTextareaCount);
    },

    bindStorage: function() {
      var self = this, names = this.storageType('do-'), saved = window.SimpleMapprStorage.current() || (names.length ? window.SimpleMapprStorage.get(names[names.length - 1]) : null);
      if (!self.vars.storageInitialized) {
        self.vars.storageInitialized = true;
        if (saved) { self.loadInputs({map: saved}); self.showMap({map: saved}); }
        else { window.SimpleMapprStorage.set('do-' + Date.now(), self.readDocument()); }
        $('#form-mapper').on('input.simplemapprStorage change.simplemapprStorage', 'input:not([type=file]),textarea,select', function() {
          try { window.SimpleMapprStorage.current(self.readDocument()); } catch (error) {}
        });
      } else {
        self.clearStorage(); window.SimpleMapprStorage.set('do-' + Date.now(), self.readDocument());
      }
    },

    bindRotateWheel: function() {
      var self = this;
      $('#mapControls').find('ul.overview').append(self.mapCircleSlider())
        .end()
        .tinycircleslider({snaptodots:true,radius:28,callback:function(element,index){
          self.unusedVariables(index);
          if($('#map-loader').find('span.mapper-loading-spinner').is(':hidden')) { self.performRotation(element); }
      }});
    },

    updateTabHash: function(index) {
      var params = new window.URLSearchParams(window.location.hash.slice(1));
      params.set('tabs', index);
      window.location.hash = params.toString();
      this.adjustLanguageLinks('tabs', index);
    },

    accessibleTabs: function(container) {
      var self = this, instance = container.tabs('instance');
      if (!instance) { return; }
      if (!instance.simplemapprSemantics) {
        instance.simplemapprSemantics = true;
        var toggle = instance._toggle;
        instance._toggle = function(event, data) {
          var result = toggle.call(this, event, data);
          self.accessibleTabs(container);
          return result;
        };
        instance._getPanelForTab = function(tab) {
          var id = $(tab).data('simplemapprPanel') || $(tab).attr('aria-controls');
          return id ? this.element.find('#' + window.CSS.escape(id)) : $();
        };
      }
      instance.tabs.each(function() {
        var tab = $(this), anchor = tab.find('>a');
        var panel = tab.attr('aria-controls') || tab.data('simplemapprPanel');
        tab.data('simplemapprPanel', panel);
        anchor.attr({role: 'tab', tabindex: tab.hasClass('ui-tabs-active') ? 0 : -1, 'aria-selected': String(tab.hasClass('ui-tabs-active')), 'aria-controls': panel, 'aria-disabled': String(tab.hasClass('ui-state-disabled'))});
        tab.attr('role', 'presentation').removeAttr('tabindex aria-controls aria-selected aria-expanded aria-labelledby aria-disabled');
        tab.off('focus.simplemapprTabs').on('focus.simplemapprTabs', function() { anchor.trigger('focus'); });
      });
      instance.tablist.off('keydown.simplemapprTabs').on('keydown.simplemapprTabs', 'a[role=tab]', function(event) {
        var index = instance.anchors.index(this), next = index, keys = ['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End','Enter',' '];
        if (!keys.includes(event.key)) { return; }
        event.preventDefault(); event.stopPropagation();
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { next++; }
        if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { next--; }
        if (event.key === 'Home') { next = 0; }
        if (event.key === 'End') { next = instance.tabs.length - 1; }
        next = (next + instance.tabs.length) % instance.tabs.length;
        if (instance.tabs.eq(next).hasClass('ui-state-disabled')) { return; }
        container.tabs('option', 'active', next); self.accessibleTabs(container); instance.anchors.eq(next).trigger('focus');
      });
    },

    bindTabs: function() {
      var self = this, tab = $('#tabs');
      $('#mapTools').tabs({active: 0, create: function() { self.accessibleTabs($(this)); }, activate: function() { self.accessibleTabs($(this)); }});
      tab.tabs({
        create: function() { self.accessibleTabs($(this)); },
        beforeLoad: function(event, ui) {
          if ($(ui.tab).data('loaded')) { event.preventDefault(); return; }
          self.showSpinner();
          ui.jqXHR.fail(function(xhr, status) {
            self.hideSpinner();
            if (status !== 'abort') { $(ui.panel).text($('#mapper-loading-error-message').text()).attr('role', 'alert'); }
          });
        },
        load: function(event, ui) { $(ui.tab).data('loaded', true); self.hideSpinner(); },
        activate: function(event, ui) { self.accessibleTabs($(this)); self.updateTabHash(ui.newTab.index()); }
      }).show();
      $(window).on('hashchange.simplemappr', function() {
        var params = new window.URLSearchParams(window.location.hash.slice(1)), index = Number(params.get('tabs') || 0);
        if (Number.isInteger(index) && index >= 0 && index < tab.find('>ul>li').length) { tab.tabs('option', 'active', index); }
      }).trigger('hashchange.simplemappr');
    },

    bindUpload: function() {
      var self = this, fileInput = $('#fileInput');
      fileInput.attr('accept', '.csv,.tsv,.txt,.wkt,.json,.geojson,.kml,.xml,.rss').on('change', function() {
        var input = this, file = input.files && input.files[0];
        if (!file) { return; }
        if (!/\.(csv|tsv|txt|wkt|json|geojson|kml|xml|rss)$/i.test(file.name) || file.size > 5 * 1024 * 1024 || !file.size) {
          self.showUnsupportedFile(); input.value = ''; return;
        }
        var body = new window.FormData(); body.append('file', file); body.append('input_format', 'auto'); body.append('encoding', $('#import-encoding').val() || 'UTF-8');
        self.showSpinner(); fileInput.prop('disabled', true);
        $.ajax({type: 'POST', url: self.settings.baseUrl + '/import', data: body, processData: false, contentType: false, dataType: 'json', timeout: 30000})
          .done(function(response) {
            try {
              var document = response.document || Object.assign(self.readDocument(), {coords: response.coords || [], wkt: response.wkt || [], regions: response.regions || []});
              document = window.SimpleMapprDocument.normalize(document, self.settings.maxTextareaCount);
              self.loadInputs({map: document}); self.destroyRedo(); self.showMap({map: document}); self.tabSelector(0);
              $('#import-status').text(response.bad_drawings && response.bad_drawings.length ? $('#mapper-import-warning').text() + ' ' + response.bad_drawings.length : $('#mapper-import-success').text());
            } catch (error) { self.hideSpinner(); self.showUnsupportedFile(); }
          })
          .fail(function(xhr) { self.hideSpinner(); self.showErrorMessage((xhr.responseJSON && xhr.responseJSON.error) || $('#mapper-loading-error-message').text()); })
          .always(function() { input.value = ''; fileInput.prop('disabled', false); });
      });
    },

    showUnsupportedFile: function() {
      $('#badFile').dialog({
        autoOpen      : true,
        height        : '200',
        width         : '500',
        dialogClass   : 'ui-dialog-title-badFile',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons: [
          {
            "text"  : "OK",
            "class" : "positive ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
            }
          }
        ]
      });
    },

    showTooMuchData: function() {
      $('#tooMuchData').dialog({
        autoOpen      : true,
        height        : '200',
        width         : '500',
        dialogClass   : 'ui-dialog-title-tooMuchData',
        modal         : true,
        closeOnEscape : true,
        draggable     : true,
        resizable     : false,
        buttons: [
          {
            "text"  : "OK",
            "class" : "positive ui-button-text-only",
            "click" : function() {
              $(this).dialog("destroy");
            }
          }
        ]
      });
    },


    adjustLanguageLinks: function(id, idx) {
      var url = "";
      $.each($('#site-languages').find('a'), function() {
        url = $(this).attr('href').split('#')[0];
        $(this).attr('href', url + '#' + id + '=' + idx);
      });
    },

    screenSizeListener: function() {
      var self = this;

      $(window).resize(function() {
        var arrPageSizes  = self.getPageSize(),
            arrPageScroll = self.getPageScroll();

        $('#mapper-overlay').css({
          width :  arrPageSizes[0],
          height:  arrPageSizes[1]
        });

        $('#mapper-message').css({
          top     : arrPageScroll[1] + (arrPageSizes[3] / 10),
          left    : arrPageScroll[0],
          position: 'fixed',
          zIndex  : 1001,
          margin  : '0px auto',
          width   : '100%'
        });
      });
    },

    unusedVariables: function() {
      return;
    },

    bindAccordions: function() {
      var self = this;
      $.each([this.vars.fieldSetsPoints, this.vars.fieldSetsRegions, this.vars.fieldSetsWKT], function() {
        $(this).accordion({header : 'h3', collapsible : true, heightStyle : "content"}).off('accordionactivate.simplemapprLabels').on('accordionactivate.simplemapprLabels', function() { self.associateGroupLabels(); });
      });
    },

    associateGroupLabels: function() {
      $('.fieldSets').removeAttr('role').find('.ui-accordion-header').attr('role', 'button').removeAttr('aria-selected').find('a').removeAttr('href tabindex');
      $('.fieldSets .ui-accordion-content').attr('role', 'region');
      $('.fieldSets > .form-item').each(function() {
        var group = $(this);
        group.find('label[data-field]').each(function() {
          var input = group.find('[name$="[' + $(this).attr('data-field') + ']"]');
          var id = 'map-field-' + String(input.attr('name')).replace(/[^A-Za-z0-9]+/g, '-');
          input.attr('id', id); $(this).attr('for', id);
        });
      });
    },

    bindTextAreaResizers: function() {
      this.vars.fieldSetsPoints.add(this.vars.fieldSetsRegions).add(this.vars.fieldSetsWKT).find('textarea').css('resize', 'vertical');
    },

    appendDialogs: function() {
      $('body').append($('#dialog-template').html());
    },

    disableDefaultButtons: function() {
      var self = this;
      $('#form-mapper').on('submit.simplemappr', function(event) {
        event.preventDefault();
        if (!self.missingFieldSetTitle()) { self.destroyRedo(); self.showMap(); self.tabSelector(0); }
      });
    },

    bindSpecialClicks: function() {
      var self = this;

      $('#general-points').find('a.show-examples').on('click', function(e) { e.preventDefault(); self.showExamples(); });
      $('#regions-introduction').find('a.show-codes').on('click', function(e) { e.preventDefault(); self.showCodes(); });
      $('#actionsBar')
        .find('a.toolsUndoDisabled').off('click').end()
        .find('a.toolsRedoDisabled').off('click');
    },

    bindTooltips: function() {
      $('#mapWrapper').find('a[title]').each(function() { $(this).attr('aria-label', this.title); });
    },

    getUserData: function() {
      var self = this;
      $('#settings-import').on('change', function() {
        var file = this.files && this.files[0];
        if (!file) { return; }
        if (file.size > 2000000) { $('#settings-import-status').text($('#settings-import-size').text()); this.value = ''; return; }
        var input = this, reader = new FileReader();
        reader.onload = function() {
          $.ajax({type:'POST', url:self.settings.baseUrl + '/usermap/import', data:{settings:reader.result}, dataType:'json'})
            .done(function() { $('#settings-import-status').text($('#settings-import-success').text()); self.loadMapList(); })
            .fail(function(xhr) { $('#settings-import-status').text((xhr.responseJSON && xhr.responseJSON.error) || $('#settings-import-error').text()); })
            .always(function() { input.value = ''; });
        };
        reader.onerror = function() { $('#settings-import-status').text($('#settings-import-read-error').text()); input.value = ''; };
        reader.readAsText(file);
      });
      if($('#usermaps').length > 0) {
        this.loadMapList();
        this.loadShareList();
        this.tabSelector(4);
      }
    },

    init: function() {
      var self = this;
      window.SimpleMapprStorage.init(this.settings.storageScope, this.settings.migrateLegacyStorage);
      this.appendDialogs();
      this.disableDefaultButtons();
      this.screenSizeListener();
      this.bindRotateWheel();
      $('#mapControls .thumb').attr({tabindex: 0, role: 'slider', 'aria-label': $('#mapper-rotation-label').text(), 'aria-valuemin': 0, 'aria-valuemax': 355, 'aria-valuenow': 0}).on('keydown', function(event) {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); var angle = (Number($('#rotation').val()) + (event.key === 'ArrowLeft' ? 355 : 5)) % 360; self.setRotation(angle); self.showMap(); }
      });
      this.bindTooltips();
      this.bindTabs();
      this.bindUpload();
      this.bindSpecialClicks();
      this.bindAccordions();
      this.bindHotkeys();
      this.bindToolbar();
      this.bindArrows();
      this.bindSettings();
      this.bindSlider();
      $('#border-slider .ui-slider-handle').attr('aria-labelledby', 'border-thickness-label');
      this.bindColorPickers();
      this.bindAddButtons();
      this.bindClearButtons();
      this.bindAutocomplete();
      this.bindSubmit();
      this.bindPanelToggle();
      this.bindTextAreaResizers();
      this.associateGroupLabels();
      this.getUserData();
      this.hideSpinner();
      this.bindStorage();
    }

  };

  return {
    init: function(args) {
      $.extend(_private.settings, args);
      _private.init();
    },
    showSpinner: function() {
      _private.showSpinner();
    },
    hideSpinner: function() {
      _private.hideSpinner();
    },
    tabSelector: function(tab) {
      _private.tabSelector(tab);
    },
    loadCodes: function(elem,data) {
      _private.loadCodes(elem,data);
    },
    loadMapList: function(obj) {
      _private.loadMapList(obj);
    },
    settings: _private.settings
  };

}(jQuery, window, document));
