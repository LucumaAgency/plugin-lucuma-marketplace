/* Lucuma Marketplace · dealer panel. No build, no dependencies. Views by hash:
 *   #/            listings      #/new          add equipment
 *   #/edit/ID     edit          #/profile      company card
 */
(function () {
  'use strict';
  var root = document.getElementById('lm-panel');
  if (!root || !window.LMP) return;

  var STATUSES = ['available', 'pending', 'sold'];
  var CONDITIONS = ['used', 'new', 'refurbished'];
  var catalog = null; // { categories, makes, byId: {id: {name, specs, parentName}} }

  /* ---------------------------------------------------------------- helpers */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return n == null ? '' : '$' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  function api(path, opts, base) {
    opts = opts || {};
    var headers = { 'X-WP-Nonce': LMP.nonce };
    var body = opts.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    return fetch((base || LMP.api) + path, { method: opts.method || (body ? 'POST' : 'GET'), headers: headers, body: body, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok) throw new Error((j && j.message) || ('Error ' + r.status));
          return j;
        });
      });
  }

  function loadCatalog() {
    if (catalog) return Promise.resolve(catalog);
    return api('catalog').then(function (c) {
      var byId = {};
      (function walk(items, parent) {
        items.forEach(function (t) {
          byId[t.id] = { id: t.id, name: t.name, specs: (parent ? parent.specs : []).concat(t.specs), parent: parent, hasChildren: t.children.length > 0 };
          walk(t.children, byId[t.id]);
        });
      })(c.categories, null);
      catalog = { categories: c.categories, makes: c.makes, byId: byId };
      return catalog;
    });
  }

  /** Specs that apply: ancestors first; a child redefining a key wins. */
  function specsOf(catId) {
    var c = catalog.byId[catId];
    if (!c) return [];
    var out = {}, order = [];
    c.specs.forEach(function (d) { if (!out[d.key]) order.push(d.key); out[d.key] = d; });
    return order.map(function (k) { return out[k]; });
  }

  function shell(title, actions, inner) {
    root.innerHTML =
      '<div class="lmp-top"><h2>' + esc(title) + '</h2><div class="lmp-actions">' + actions + '</div></div>' +
      '<div class="lmp-msg" role="status"></div>' + inner;
  }
  function message(text, bad) {
    var m = root.querySelector('.lmp-msg');
    if (!m) return;
    m.textContent = text || '';
    m.className = 'lmp-msg' + (text ? (bad ? ' bad' : ' ok') : '');
    if (text && !bad) setTimeout(function () { if (m.textContent === text) { m.textContent = ''; m.className = 'lmp-msg'; } }, 4000);
  }
  function nav() {
    return '<a class="lmp-btn ghost" href="#/profile">Company profile</a><a class="lmp-btn ghost" href="' + esc(LMP.logout) + '">Log out</a>';
  }

  /* ---------------------------------------------------------------- list */
  function viewList() {
    shell('Your equipment', '<a class="lmp-btn" href="#/new">+ Add equipment</a>' + nav(),
      '<div class="lmp-bar"><input type="search" class="lmp-search" placeholder="Search by title or stock number"></div><div class="lmp-list"><p class="lmp-muted">Loading…</p></div>');
    var box = root.querySelector('.lmp-list');
    var search = root.querySelector('.lmp-search');
    var timer;
    function load() {
      api('my/equipment' + (search.value ? '?q=' + encodeURIComponent(search.value) : '')).then(function (rows) {
        if (!rows.length) {
          box.innerHTML = '<div class="lmp-empty"><p><strong>' + (search.value ? 'Nothing matches your search.' : 'No equipment yet.') + '</strong></p>' +
            (search.value ? '' : '<p class="lmp-muted">Add your first unit: category, make and model, a few photos and the price.</p><a class="lmp-btn" href="#/new">+ Add equipment</a>') + '</div>';
          return;
        }
        box.innerHTML = '<table class="lmp-table"><thead><tr><th></th><th>Equipment</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>' +
          rows.map(function (r) {
            return '<tr data-id="' + r.id + '">' +
              '<td class="lmp-thumb">' + (r.thumb ? '<img src="' + esc(r.thumb) + '" alt="">' : '<span></span>') + '</td>' +
              '<td><a class="lmp-name" href="#/edit/' + r.id + '">' + esc(r.title) + '</a><div class="lmp-muted">' +
                esc([r.category && r.category.name, r.stock && ('Stock #' + r.stock), LMP.admin && r.dealer].filter(Boolean).join(' · ')) +
                (r.published ? '' : ' <span class="lmp-badge">Draft</span>') + '</div></td>' +
              '<td>' + (r.call ? 'Call' : esc(money(r.price)) || '—') + '</td>' +
              '<td><select class="lmp-status" aria-label="Status">' + STATUSES.map(function (s) {
                return '<option value="' + s + '"' + (s === r.status ? ' selected' : '') + '>' + cap(s) + '</option>';
              }).join('') + '</select></td>' +
              '<td class="lmp-row-actions"><a href="#/edit/' + r.id + '">Edit</a>' + (r.published ? ' · <a href="' + esc(r.link) + '" target="_blank" rel="noopener">View</a>' : '') +
                ' · <button type="button" class="lmp-link danger" data-del>Delete</button></td></tr>';
          }).join('') + '</tbody></table>';
      }).catch(function (e) { box.innerHTML = ''; message(e.message, true); });
    }
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 300); });
    box.addEventListener('change', function (e) {
      if (!e.target.classList.contains('lmp-status')) return;
      var id = e.target.closest('tr').dataset.id;
      api('my/equipment/' + id + '/status', { body: { status: e.target.value } })
        .then(function () { message('Status updated.'); })
        .catch(function (err) { message(err.message, true); load(); });
    });
    box.addEventListener('click', function (e) {
      if (!e.target.hasAttribute('data-del')) return;
      var tr = e.target.closest('tr');
      if (!window.confirm('Delete this listing? It goes to the trash and stops showing on the site.')) return;
      api('my/equipment/' + tr.dataset.id, { method: 'DELETE' }).then(function () { message('Listing deleted.'); load(); })
        .catch(function (err) { message(err.message, true); });
    });
    load();
  }

  /* ---------------------------------------------------------------- form */
  function field(label, html, hint, wide) {
    return '<label class="lmp-field' + (wide ? ' wide' : '') + '"><span>' + esc(label) + '</span>' + html + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
  }
  function input(name, value, type, attrs) {
    return '<input name="' + name + '" type="' + (type || 'text') + '" value="' + esc(value == null ? '' : value) + '" ' + (attrs || '') + '>';
  }
  function select(name, options, value, empty) {
    return '<select name="' + name + '">' + (empty ? '<option value="">' + esc(empty) + '</option>' : '') + options.map(function (o) {
      var v = typeof o === 'object' ? o.value : o, l = typeof o === 'object' ? o.label : cap(o);
      return '<option value="' + esc(v) + '"' + (String(v) === String(value) ? ' selected' : '') + '>' + esc(l) + '</option>';
    }).join('') + '</select>';
  }
  function categoryOptions(value) {
    var html = '<option value="">Choose a category</option>', loose = [];
    catalog.categories.forEach(function (c) {
      if (!c.children.length) { loose.push(c); return; }
      html += '<optgroup label="' + esc(c.name) + '">' + c.children.map(function (k) {
        return '<option value="' + k.id + '"' + (String(k.id) === String(value) ? ' selected' : '') + '>' + esc(k.name) + '</option>';
      }).join('') + '</optgroup>';
    });
    if (loose.length) html += '<optgroup label="Other">' + loose.map(function (k) {
      return '<option value="' + k.id + '"' + (String(k.id) === String(value) ? ' selected' : '') + '>' + esc(k.name) + '</option>';
    }).join('') + '</optgroup>';
    return '<select name="category" required>' + html + '</select>';
  }
  function specInput(d, v) {
    var name = 'spec_' + d.key, label = d.label + (d.unit ? ' (' + d.unit + ')' : '');
    if (d.type === 'boolean') return '<label class="lmp-check"><input type="checkbox" name="' + name + '"' + (v ? ' checked' : '') + '> ' + esc(d.label) + '</label>';
    if (d.type === 'select') return field(label, select(name, d.options.map(function (o) { return { value: o, label: o }; }), v, '—'));
    return field(label, input(name, v, d.type === 'number' ? 'number' : 'text', d.type === 'number' ? 'step="any" min="0"' : 'maxlength="200"'));
  }

  function viewForm(id) {
    shell(id ? 'Edit equipment' : 'Add equipment', '<a class="lmp-btn ghost" href="#/">← Back to list</a>', '<p class="lmp-muted">Loading…</p>');
    Promise.all([loadCatalog(), id ? api('my/equipment/' + id) : Promise.resolve(null)]).then(function (res) {
      renderForm(res[1] || { status: 'available', condition: 'used', specs: {}, gallery: [] });
    }).catch(function (e) { message(e.message, true); });
  }

  function renderForm(eq) {
    var catId = eq.category ? eq.category.id : '';
    var makes = catalog.makes.map(function (m) { return { value: m.id, label: m.name }; });
    var gallery = (eq.gallery || []).slice();

    var html =
      '<form class="lmp-form" novalidate>' +
      '<section><h3>What it is</h3><div class="lmp-grid">' +
        field('Category', categoryOptions(catId)) +
        field('Make', select('make', makes, eq.make ? eq.make.id : '', 'Choose a make')) +
        field('Model', input('model', eq.model ? eq.model.name : '', 'text', 'list="lmp-models" autocomplete="off" required maxlength="80"') + '<datalist id="lmp-models"></datalist>') +
        field('Year', input('year', eq.year, 'number', 'min="1900" max="' + (new Date().getFullYear() + 1) + '"')) +
        field('Hours', input('hours', eq.hours, 'number', 'min="0"')) +
        field('Mileage (mi)', input('mileage', eq.mileage, 'number', 'min="0"'), 'Trucks and trailers') +
        field('Condition', select('condition', CONDITIONS, eq.condition)) +
        field('Title', input('title', eq.title, 'text', 'maxlength="200" placeholder="Auto: year, make, model and category"'), '', true) +
      '</div></section>' +
      '<section><h3>Price and availability</h3><div class="lmp-grid">' +
        field('Price (USD)', input('price', eq.price, 'number', 'min="0" step="1"')) +
        '<label class="lmp-check lmp-align"><input type="checkbox" name="call"' + (eq.call ? ' checked' : '') + '> Call for price</label>' +
        field('Status', select('status', STATUSES, eq.status)) +
        field('Stock number', input('stock', eq.stock, 'text', 'maxlength="40"')) +
        field('Serial / VIN', input('serial', eq.serial, 'text', 'maxlength="64"'), 'Private: never shown on the site') +
      '</div></section>' +
      '<section><h3>Location</h3><div class="lmp-grid">' +
        field('City', input('city', eq.city, 'text', 'maxlength="80"')) +
        field('State', input('state', eq.state, 'text', 'maxlength="2" placeholder="PA"')) +
        field('ZIP', input('zip', eq.zip, 'text', 'maxlength="10" inputmode="numeric"')) +
      '</div></section>' +
      '<section><h3>Specs</h3><div class="lmp-grid lmp-specs"></div></section>' +
      '<section><h3>Photos</h3><div class="lmp-drop" tabindex="0"><p><strong>Drop photos here</strong> or <button type="button" class="lmp-link" data-pick>choose files</button>. The first one is the cover; drag to reorder.</p>' +
        '<input type="file" accept="image/*" multiple hidden><div class="lmp-photos"></div></div></section>' +
      '<section><h3>Description</h3>' + field('', '<textarea name="description" rows="6" maxlength="10000">' + esc(eq.description || '') + '</textarea>', '', true) +
        field('Video URL', input('video_url', eq.video_url, 'url', 'placeholder="https://youtube.com/…"'), '', true) + '</section>' +
      '<div class="lmp-save"><button class="lmp-btn" type="submit">' + (eq.id && !eq.published ? 'Publish' : eq.id ? 'Save changes' : 'Publish') + '</button>' +
        '<button class="lmp-btn ghost" type="button" data-draft>Save as draft</button>' +
        (eq.id && eq.published ? '<a class="lmp-link" href="' + esc(eq.link) + '" target="_blank" rel="noopener">View on site</a>' : '') + '</div>' +
      '</form>';
    root.querySelector('.lmp-msg').insertAdjacentHTML('afterend', html);
    root.querySelectorAll('.lmp-top + .lmp-msg ~ p.lmp-muted').forEach(function (p) { p.remove(); });

    var form = root.querySelector('.lmp-form');
    var specsBox = form.querySelector('.lmp-specs');

    function drawSpecs() {
      var c = form.elements.category.value, defs = c ? specsOf(c) : [];
      var current = collectSpecs();
      Object.keys(eq.specs || {}).forEach(function (k) { if (!(k in current)) current[k] = eq.specs[k]; });
      specsBox.innerHTML = defs.length ? defs.map(function (d) { return specInput(d, current[d.key]); }).join('') :
        '<p class="lmp-muted">' + (c ? 'This category has no extra specs.' : 'Choose a category to see its specs.') + '</p>';
    }
    function collectSpecs() {
      var out = {};
      specsBox.querySelectorAll('[name^="spec_"]').forEach(function (el) {
        var k = el.name.slice(5);
        if (el.type === 'checkbox') out[k] = el.checked;
        else if (el.value !== '') out[k] = el.value;
      });
      return out;
    }
    function loadModels() {
      var list = form.querySelector('#lmp-models');
      list.innerHTML = '';
      if (!form.elements.make.value) return;
      api('models?make=' + form.elements.make.value).then(function (ms) {
        list.innerHTML = ms.map(function (m) { return '<option value="' + esc(m.name) + '">'; }).join('');
      }).catch(function () {});
    }
    form.elements.category.addEventListener('change', drawSpecs);
    form.elements.make.addEventListener('change', loadModels);
    drawSpecs();
    loadModels();

    /* photos */
    var drop = form.querySelector('.lmp-drop'), picker = drop.querySelector('input[type=file]'), photos = drop.querySelector('.lmp-photos');
    var dragFrom = null;
    function drawPhotos() {
      photos.innerHTML = gallery.map(function (g, i) {
        return '<figure draggable="true" data-i="' + i + '"' + (g.uploading ? ' class="up"' : '') + '>' +
          (g.url ? '<img src="' + esc(g.url) + '" alt="">' : '<span>' + Math.round((g.progress || 0) * 100) + '%</span>') +
          (i === 0 && !g.uploading ? '<em>Cover</em>' : '') +
          (g.uploading ? '' : '<button type="button" aria-label="Remove photo" data-rm="' + i + '">×</button>') + '</figure>';
      }).join('');
    }
    function upload(files) {
      Array.prototype.forEach.call(files, function (file) {
        if (!/^image\//.test(file.type)) { message(file.name + ' is not an image.', true); return; }
        if (file.size > LMP.maxMb * 1048576) { message(file.name + ' is larger than ' + LMP.maxMb + ' MB.', true); return; }
        var slot = { uploading: true, progress: 0 };
        gallery.push(slot); drawPhotos();
        var fd = new FormData(); fd.append('file', file, file.name);
        api('media', { body: fd }, LMP.wpapi).then(function (m) {
          slot.uploading = false; slot.id = m.id;
          slot.url = (m.media_details && m.media_details.sizes && m.media_details.sizes.medium && m.media_details.sizes.medium.source_url) || m.source_url;
          drawPhotos();
        }).catch(function (e) { gallery.splice(gallery.indexOf(slot), 1); drawPhotos(); message(file.name + ': ' + e.message, true); });
      });
    }
    drop.querySelector('[data-pick]').addEventListener('click', function () { picker.click(); });
    picker.addEventListener('change', function () { upload(picker.files); picker.value = ''; });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { if (dragFrom === null) { e.preventDefault(); drop.classList.add('over'); } }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) { if (dragFrom === null && e.dataTransfer.files.length) { e.preventDefault(); upload(e.dataTransfer.files); } });
    photos.addEventListener('click', function (e) { var i = e.target.getAttribute('data-rm'); if (i !== null) { gallery.splice(+i, 1); drawPhotos(); } });
    photos.addEventListener('dragstart', function (e) { var f = e.target.closest('figure'); if (f) { dragFrom = +f.dataset.i; e.dataTransfer.effectAllowed = 'move'; } });
    photos.addEventListener('dragover', function (e) { if (dragFrom !== null) e.preventDefault(); });
    photos.addEventListener('drop', function (e) {
      var f = e.target.closest('figure');
      if (dragFrom === null || !f) return;
      e.preventDefault(); e.stopPropagation();
      var to = +f.dataset.i, moved = gallery.splice(dragFrom, 1)[0];
      gallery.splice(to, 0, moved); dragFrom = null; drawPhotos();
    });
    photos.addEventListener('dragend', function () { dragFrom = null; });
    drawPhotos();

    /* save */
    function save(draft) {
      if (gallery.some(function (g) { return g.uploading; })) { message('Wait for the photos to finish uploading.', true); return; }
      if (!form.elements.category.value || !form.elements.make.value || !form.elements.model.value.trim()) { message('Category, make and model are required.', true); return; }
      var body = {
        draft: !!draft,
        category: form.elements.category.value, make: form.elements.make.value, model: form.elements.model.value.trim(),
        title: form.elements.title.value, year: form.elements.year.value, hours: form.elements.hours.value, mileage: form.elements.mileage.value,
        condition: form.elements.condition.value, price: form.elements.price.value, call: form.elements.call.checked, status: form.elements.status.value,
        stock: form.elements.stock.value, serial: form.elements.serial.value, city: form.elements.city.value, state: form.elements.state.value, zip: form.elements.zip.value,
        description: form.elements.description.value, video_url: form.elements.video_url.value,
        specs: collectSpecs(), gallery: gallery.map(function (g) { return g.id; })
      };
      var buttons = form.querySelectorAll('button');
      buttons.forEach(function (b) { b.disabled = true; });
      api('my/equipment' + (eq.id ? '/' + eq.id : ''), { body: body }).then(function (saved) {
        if (!eq.id) { location.hash = '#/edit/' + saved.id; setTimeout(function () { message(draft ? 'Saved as draft.' : 'Published.'); }, 50); return; }
        eq = saved; root.querySelector('.lmp-form').remove(); renderForm(saved); message(draft ? 'Saved as draft.' : 'Saved.');
      }).catch(function (e) { message(e.message, true); buttons.forEach(function (b) { b.disabled = false; }); });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); save(false); });
    form.querySelector('[data-draft]').addEventListener('click', function () { save(true); });
  }

  /* ---------------------------------------------------------------- profile */
  function viewProfile() {
    shell('Company profile', '<a class="lmp-btn ghost" href="#/">← Back to list</a>', '<p class="lmp-muted">Loading…</p>');
    api('my/profile').then(function (p) {
      var logo = p.logo;
      root.querySelector('p.lmp-muted').remove();
      root.insertAdjacentHTML('beforeend',
        '<form class="lmp-form"><section><h3>How buyers see you</h3><div class="lmp-grid">' +
          field('Company name', input('company', p.company, 'text', 'maxlength="120" required')) +
          field('Contact name', input('name', p.name, 'text', 'maxlength="120"')) +
          field('Phone', input('phone', p.phone, 'tel', 'maxlength="30"')) +
          field('Website', input('website', p.website, 'url', 'placeholder="https://"')) +
          field('Address', input('address', p.address, 'text', 'maxlength="160"'), '', true) +
          field('City', input('city', p.city, 'text', 'maxlength="80"')) +
          field('State', input('state', p.state, 'text', 'maxlength="2"')) +
          field('ZIP', input('zip', p.zip, 'text', 'maxlength="10"')) +
        '</div></section><section><h3>Logo</h3><div class="lmp-logo"></div><input type="file" accept="image/*" hidden>' +
        '<button type="button" class="lmp-btn ghost" data-logo>Upload logo</button></section>' +
        '<div class="lmp-save"><button class="lmp-btn" type="submit">Save profile</button><span class="lmp-muted">Login email: ' + esc(p.email) + '</span></div></form>');
      var form = root.querySelector('.lmp-form'), file = form.querySelector('input[type=file]'), box = form.querySelector('.lmp-logo');
      function drawLogo() { box.innerHTML = logo ? '<img src="' + esc(logo.url) + '" alt="Logo"> <button type="button" class="lmp-link danger" data-rmlogo>Remove</button>' : '<p class="lmp-muted">No logo yet.</p>'; }
      drawLogo();
      form.querySelector('[data-logo]').addEventListener('click', function () { file.click(); });
      box.addEventListener('click', function (e) { if (e.target.hasAttribute('data-rmlogo')) { logo = null; drawLogo(); } });
      file.addEventListener('change', function () {
        if (!file.files[0]) return;
        var fd = new FormData(); fd.append('file', file.files[0], file.files[0].name);
        message('Uploading logo…');
        api('media', { body: fd }, LMP.wpapi).then(function (m) { logo = { id: m.id, url: m.source_url }; drawLogo(); message('Logo uploaded. Save to keep it.'); })
          .catch(function (e) { message(e.message, true); });
        file.value = '';
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var b = { logo: logo ? logo.id : 0 };
        ['company', 'name', 'phone', 'website', 'address', 'city', 'state', 'zip'].forEach(function (k) { b[k] = form.elements[k].value; });
        api('my/profile', { body: b }).then(function () { message('Profile saved.'); }).catch(function (err) { message(err.message, true); });
      });
    }).catch(function (e) { message(e.message, true); });
  }

  /* ---------------------------------------------------------------- router */
  function route() {
    var h = location.hash.replace(/^#\/?/, '');
    var m = h.match(/^edit\/(\d+)$/);
    if (m) return viewForm(+m[1]);
    if (h === 'new') return viewForm(null);
    if (h === 'profile') return viewProfile();
    return viewList();
  }
  window.addEventListener('hashchange', route);
  route();
})();
