(function(window) {
  'use strict';
  var scalars = ['projection', 'projection_map', 'bbox_map', 'bbox_rubberband', 'rotation', 'origin', 'origin_map', 'border_thickness', 'grid_space', 'gridspace', 'hide_gridlabel', 'download_factor', 'download_filetype', 'width', 'height', 'output', 'file_name'];
  var groups = {coords: ['title', 'data', 'color', 'shadow', 'shape', 'size'], wkt: ['title', 'data', 'color', 'border', 'hatch'], regions: ['title', 'data', 'color', 'border', 'hatch']};
  var options = ['border', 'legend', 'scalebar', 'scalelinethickness'];
  function scalar(value, limit) {
    if (value !== null && !['string','number','boolean'].includes(typeof value)) { throw new Error('Invalid map setting.'); }
    if (typeof value === 'number' && !Number.isFinite(value)) { throw new Error('Invalid map setting.'); }
    if (String(value === null ? '' : value).length > limit) { throw new Error('Map setting is too long.'); }
    return value;
  }
  function normalize(source, maximumGroups) {
    source = source && source.map ? source.map : source;
    if (!source || typeof source !== 'object' || Array.isArray(source)) { throw new Error('Invalid map settings.'); }
    if (source.schema_version !== undefined && source.schema_version !== 1) { throw new Error('Unsupported map settings version.'); }
    var document = {schema_version: 1};
    scalars.forEach(function(key) {
      if (Object.hasOwn(source,key)) { document[key] = scalar(key === 'bbox_rubberband' && Array.isArray(source[key]) && !source[key].length ? '' : source[key], 2048); }
    });
    document.save = {title: String(scalar(source.save && source.save.title || '', 255)).trim()};
    Object.keys(groups).forEach(function(key) {
      var rows = source[key] || [];
      if (!Array.isArray(rows) && Object.hasOwn(rows,'data')) { rows = [rows]; }
      if (!Array.isArray(rows) || rows.length > (maximumGroups || 10)) { throw new Error('Too many map data groups.'); }
      document[key] = rows.map(function(row) {
        if (!row || typeof row !== 'object' || Array.isArray(row)) { throw new Error('Invalid map data group.'); }
        var normalized = {};
        groups[key].forEach(function(field) { if (Object.hasOwn(row,field)) { normalized[field] = scalar(row[field],field === 'data' ? 500000 : 1024); } });
        normalized.title = String(normalized.title || ''); normalized.data = String(normalized.data || '');
        return normalized;
      });
    });
    ['layers','options'].forEach(function(key) {
      var entries = source[key] || {};
      if (Array.isArray(entries) && entries.length === 0) { entries = {}; }
      if (typeof entries !== 'object' || Array.isArray(entries) || Object.keys(entries).length > 60) { throw new Error('Invalid map options.'); }
      document[key] = {};
      Object.keys(entries).forEach(function(name) {
        if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(name) || (key === 'options' && !options.includes(name))) { throw new Error('Invalid map option name.'); }
        document[key][name] = scalar(entries[name],32);
      });
    });
    if (new window.Blob([JSON.stringify(document)]).size > 2000000) { throw new Error('Map settings exceed 2 MB.'); }
    return document;
  }
  function fromEntries(entries, maximumGroups) {
    var source = {};
    entries.forEach(function(entry) {
      var name = entry.name, value = entry.value, match = name.match(/^(coords|wkt|regions)\[(\d+)\]\[([A-Za-z]+)\]$/);
      if (match) {
        var index = Number(match[2]);
        if (index >= (maximumGroups || 10)) { throw new Error('Too many map data groups.'); }
        if (!source[match[1]]) { source[match[1]] = []; }
        if (!source[match[1]][index]) { source[match[1]][index] = {}; }
        source[match[1]][index][match[3]] = value;
        return;
      }
      match = name.match(/^(layers|options|save)\[([A-Za-z][A-Za-z0-9_]*)\]$/);
      if (match) { if (!source[match[1]]) { source[match[1]] = {}; } source[match[1]][match[2]] = value; return; }
      if (scalars.includes(name)) { source[name] = value; }
    });
    Object.keys(groups).forEach(function(key) { if (source[key]) { source[key] = source[key].filter(Boolean); } });
    return normalize(source,maximumGroups);
  }
  function enabled(value) { return value === true || value === 1 || (typeof value === 'string' && ['1','on','true','yes'].includes(value.trim().toLowerCase())); }
  window.SimpleMapprDocument = {normalize: normalize, fromEntries: fromEntries, enabled: enabled};
}(window));
