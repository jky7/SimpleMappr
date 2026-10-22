const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const template = read('views/main.html').split('\n').find(line => line.includes('<div id="colorpicker">'))
  .replace(/{{ "([^"]+)"\|trans }}/g, '$1');

function picker(t, options = {}) {
  const dom = new JSDOM(`<!doctype html><body>${template}<input id="color">`, {runScripts: 'outside-only'});
  t.after(() => dom.window.close());
  const window = dom.window;
  window.eval(read('node_modules/jquery/dist/jquery.js'));
  window.eval(read('public/javascript/jquery.colorpicker.js'));
  const $ = window.jQuery;
  const input = $('#color').ColorPicker({color: '969696', ...options});
  input.ColorPickerShow();
  const panel = $('#' + input.data('colorpickerId'));
  return {window, $, input, panel};
}

function click(e, target, x, y) {
  target.trigger(e.$.Event('mousedown', {pageX: x, pageY: y}));
  e.$(e.window.document).trigger(e.$.Event('mouseup', {pageX: x, pageY: y}));
}

function rgb(e) {
  return ['r', 'g', 'b'].map(channel => Number(e.panel.find(`.colorpicker_rgb_${channel} input`).val()));
}

test('one saturation/value click updates the RGB fields and selected color', t => {
  let selected;
  const e = picker(t, {onChange(hsb, hex) { selected = hex; }});
  click(e, e.panel.find('.colorpicker_color'), 120, 30);
  assert.deepEqual(rgb(e), [204, 41, 204]);
  assert.equal(e.panel.find('.colorpicker_hex input').val(), 'cc29cc');
  assert.equal(selected, 'cc29cc');
  assert.equal(e.panel.find('.colorpicker_new_color').css('backgroundColor'), 'rgb(204, 41, 204)');
});

test('one hue click updates the hue and RGB fields', t => {
  const e = picker(t, {color: 'ff0000'});
  click(e, e.panel.find('.colorpicker_hue'), 0, 100);
  assert.equal(e.panel.find('.colorpicker_hsb_h input').val(), '120');
  assert.deepEqual(rgb(e), [0, 255, 0]);
  assert.equal(e.panel.find('.colorpicker_hex input').val(), '00ff00');
});

test('dragging still updates saturation/value and hue until mouseup', t => {
  const e = picker(t, {color: 'ff0000'});
  e.panel.find('.colorpicker_color').trigger(e.$.Event('mousedown', {pageX: 100, pageY: 50}));
  e.$(e.window.document).trigger(e.$.Event('mousemove', {pageX: 150, pageY: 0}));
  e.$(e.window.document).trigger('mouseup');
  assert.deepEqual(rgb(e), [255, 0, 0]);
  e.panel.find('.colorpicker_hue').trigger(e.$.Event('mousedown', {pageY: 100}));
  e.$(e.window.document).trigger(e.$.Event('mousemove', {pageY: 75}));
  e.$(e.window.document).trigger('mouseup');
  assert.deepEqual(rgb(e), [0, 255, 255]);
  e.$(e.window.document).trigger(e.$.Event('mousemove', {pageX: 0, pageY: 150}));
  assert.deepEqual(rgb(e), [0, 255, 255]);
});

test('manual RGB entry still updates the selected color', t => {
  let selected;
  const e = picker(t, {onChange(hsb, hex) { selected = hex; }});
  for (const [channel, value] of [['r', 12], ['g', 34], ['b', 56]]) {
    e.panel.find(`.colorpicker_rgb_${channel} input`).val(value);
  }
  e.panel.find('.colorpicker_rgb_b input').trigger('change');
  assert.deepEqual(rgb(e), [12, 34, 56]);
  assert.equal(selected, '0c2238');
});

test('the template Apply button submits through the editor and enables fill selection', t => {
  const e = picker(t);
  e.$('body').append('<ul id="actionsBar"><li><a class="toolsQuery">Fill</a></li></ul>' +
    '<input id="bbox_rubberband"><input id="bbox_query">' +
    '<div id="mapOutput"><img id="mapOutputImage" width="900" height="450"></div>');
  e.window.eval(read('public/javascript/jquery.Jcrop.js'));
  e.window.eval(read('public/javascript/simplemappr.js').replace('settings: _private.settings',
    'settings: _private.settings, _test: _private'));
  const editor = e.window.SimpleMappr._test;
  editor.bindToolbar();
  const tool = e.$('a.toolsQuery');
  tool.ColorPickerShow().ColorPickerSetColor('336699');
  const panel = e.$('#' + tool.data('colorpickerId'));
  assert.notEqual(panel.css('display'), 'none');
  panel.find('button.colorpicker_submit').trigger('click');
  assert.equal(panel.css('display'), 'none');
  assert.deepEqual([editor.vars.fillColor.r, editor.vars.fillColor.g, editor.vars.fillColor.b], [51, 102, 153]);
  assert.equal(editor.vars.jCropType, 'query');
  assert.equal(editor.vars.zoom, false);
  assert.equal(e.$('#mapOutput .jcrop-tracker').length > 0, true);
});
