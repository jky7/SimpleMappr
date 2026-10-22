(function(window) {
  'use strict';
  var key = null, state = empty();
  function empty() { return {schema_version: 1, history: {}, crop: null, current: null}; }
  function historyKey(value) { return /^(?:do|undo)-\d+$/.test(value); }
  function persist() {
    if (!key) { return; }
    for (;;) {
      try { window.localStorage.setItem(key,JSON.stringify(state)); return; }
      catch (error) {
        if (!['QuotaExceededError','NS_ERROR_DOM_QUOTA_REACHED'].includes(error.name) && ![22,1014].includes(error.code)) { return; }
        var oldest = Object.keys(state.history).sort()[0];
        if (!oldest) { return; }
        delete state.history[oldest];
      }
    }
  }
  function normalize(value) { return window.SimpleMapprDocument.normalize(value); }
  window.SimpleMapprStorage = {
    init: function(scope, migrateLegacy) {
      key = 'simplemappr.editor.v1.' + encodeURIComponent(scope || 'guest'); state = empty();
      try {
        var saved = JSON.parse(window.localStorage.getItem(key) || 'null');
        var legacy = !saved && migrateLegacy ? JSON.parse(window.localStorage.getItem('jStorage') || 'null') : null;
        var history = saved && saved.schema_version === 1 ? saved.history : legacy;
        if (history && typeof history === 'object') {
          Object.keys(history).filter(historyKey).sort().slice(-20).forEach(function(name) {
            try { state.history[name] = normalize(history[name]); } catch (error) {}
          });
        }
        if (saved && saved.schema_version === 1) {
          if (typeof saved.crop === 'string' && saved.crop.length < 4096) { state.crop = saved.crop; }
          if (saved.current) { try { state.current = normalize(saved.current); } catch (error) {} }
        }
        if (!state.current) { var names = Object.keys(state.history).filter(name => name.startsWith('do-')).sort(); if (names.length) { state.current = state.history[names[names.length - 1]]; } }
      } catch (error) {}
      persist();
    },
    index: function() { return Object.keys(state.history).sort(); },
    get: function(name) { return state.history[name] ? normalize(state.history[name]) : null; },
    set: function(name,value) {
      if (!historyKey(name)) { throw new Error('Invalid map history key.'); }
      state.history[name] = normalize(value); state.current = state.history[name];
      var names = Object.keys(state.history).sort();
      while (names.length > 20) { delete state.history[names.shift()]; }
      persist();
    },
    deleteKey: function(name) { delete state.history[name]; persist(); },
    flush: function() { state.history = {}; state.current = null; persist(); },
    current: function(value) {
      if (arguments.length) { state.current = normalize(value); persist(); }
      return state.current ? normalize(state.current) : null;
    },
    crop: function(value) {
      if (arguments.length) { state.crop = value === null ? null : String(value).slice(0,4096); persist(); }
      return state.crop;
    }
  };
}(window));
