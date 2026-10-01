document.addEventListener('DOMContentLoaded', function() {
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token) {
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(f) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'csrf_token';
            inp.value = token.content;
            f.appendChild(inp);
        });
    }
    setTimeout(function() {
        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(el) {
            var bs = new bootstrap.Alert(el);
            bs.close();
        });
    }, 6000);

    // ---- Gradebook grid: Excel-style navigation, autosave, live recompute ----
    var gb = document.getElementById('gradebookGrid');
    if (gb) initGradebook(gb);
});

/* ================================================================
   GRADEBOOK
   Students down the left, activities across the top. Typing a cell
   saves it in the background and patches PS / IG / TG / descriptor /
   class average in place, straight from grade_engine.php.
   ================================================================ */
function initGradebook(gb) {
    var form = document.getElementById('gradebookForm');
    var statusEl = document.getElementById('gbStatus');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var inputs = Array.prototype.slice.call(gb.querySelectorAll('input.gb-input'));
    if (!inputs.length) { wireCollapse(); return; }

    // rows[r] = inputs of student row r, in visual order (PS cells are not focusable)
    var rows = [];
    inputs.forEach(function(inp) {
        var r = +inp.dataset.ri;
        if (!rows[r]) rows[r] = [];
        rows[r].push(inp);
    });
    function colCount(r) { return rows[r] ? rows[r].length : 0; }

    function focus(r, c) {
        while (r < rows.length && !rows[r]) r++;
        if (r >= rows.length) return null;
        if (c < 0) { // wrap to the end of the previous row
            var p = r - 1;
            while (p >= 0 && !rows[p]) p--;
            if (p < 0) return focus(r, 0);
            return focus(p, colCount(p) - 1);
        }
        var row = rows[r];
        if (c >= row.length) return focus(r + 1, 0); // wrap to the next row
        var t = row[c];
        if (t) { t.focus(); t.select(); }
        return t;
    }

    // ---------- cell cursor ----------
    var activeCell = null;
    function setCursor(inp) {
        if (activeCell) {
            activeCell.parentNode.classList.remove('gb-active');
            var pr = activeCell.closest('tr');
            if (pr) pr.classList.remove('gb-hi-row');
            gb.querySelectorAll('th.gb-hi-col').forEach(function(t) { t.classList.remove('gb-hi-col'); });
        }
        if (!inp) { activeCell = null; return; }
        activeCell = inp;
        var td = inp.parentNode;
        td.classList.add('gb-active');
        var tr = inp.closest('tr');
        if (tr) tr.classList.add('gb-hi-row');
        var key = td.dataset.col;
        if (key) {
            gb.querySelectorAll('thead th[data-col="' + key + '"]').forEach(function(t) {
                t.classList.add('gb-hi-col');
            });
        }
    }

    // ---------- dirty tracking ----------
    var committed = {};   // "item:student" -> last value known to be saved
    var pending = {};     // "item:student" -> value waiting to be sent
    var inFlight = false;
    var timer = null;
    var statusTimer = null;

    inputs.forEach(function(i) { committed[i.dataset.item + ':' + i.dataset.student] = i.value; });

    function key(inp) { return inp.dataset.item + ':' + inp.dataset.student; }
    function isDirty(inp) { return inp.value !== (committed[key(inp)] !== undefined ? committed[key(inp)] : ''); }
    function countDirty() { return inputs.filter(isDirty).length; }
    function countOver() { return gb.querySelectorAll('.cell-over').length; }

    function setStatus(text, cls, hold) {
        if (!statusEl) return;
        clearTimeout(statusTimer);
        statusEl.textContent = text || '\u00a0';
        statusEl.className = 'small ' + (cls || 'text-muted');
        // A held message stays on screen until the timer fires, so the async
        // save response cannot wipe out "Pasted 21 cells" before it is read.
        if (hold) statusTimer = setTimeout(function() { statusTimer = null; refreshStatus(); }, hold);
    }

    function refreshStatus() {
        if (!statusEl) return;
        if (statusTimer) return; // a message is still being displayed
        var over = countOver();
        var dirty = countDirty();
        if (over) { setStatus(over + ' over max! Not saved \u2014 the value cannot exceed the activity maximum.', 'text-danger fw-semibold'); }
        else if (inFlight) { setStatus('Saving\u2026', 'text-muted'); }
        else if (dirty) { setStatus(dirty + ' unsaved change(s)\u2026', 'text-warning-emphasis fw-semibold'); }
        else { setStatus('All scores saved', 'text-muted'); }
    }

    function checkOver(inp) {
        var mx = parseFloat(inp.max);
        var v = inp.value === '' ? NaN : parseFloat(inp.value);
        var over = !isNaN(mx) && !isNaN(v) && (v > mx || v < 0);
        inp.classList.toggle('cell-over', over);
        return over;
    }

    // ---------- queue + save ----------
    function mark(inp) {
        var k = key(inp);
        if (inp.value === (committed[k] !== undefined ? committed[k] : '')) delete pending[k];
        else pending[k] = inp.value;
        inp.classList.toggle('cell-dirty', isDirty(inp));
    }

    function flush() {
        if (inFlight) return;
        var keys = Object.keys(pending);
        if (!keys.length) { refreshStatus(); return; }

        var changes = {};
        keys.forEach(function(k) {
            var p = k.split(':');
            if (!changes[p[0]]) changes[p[0]] = {};
            changes[p[0]][p[1]] = pending[k];
        });
        pending = {};
        inFlight = true;
        refreshStatus();

        fetch(gb.dataset.api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({
                class_id: +gb.dataset.class,
                term_id: +gb.dataset.term,
                changes: changes
            })
        })
        .then(function(r) { return r.json().then(function(j) { return { status: r.status, body: j }; }); })
        .then(function(res) {
            inFlight = false;
            if (!res.body || res.body.ok !== true) {
                // Put the values back so nothing is silently lost.
                keys.forEach(function(k) {
                    var p = k.split(':');
                    var inp = find(p[0], p[1]);
                    if (inp) { inp.value = committed[k] !== undefined ? committed[k] : ''; inp.classList.remove('cell-dirty'); checkOver(inp); }
                });
                pending = {};
                setStatus('Save failed: ' + ((res.body && res.body.error) || 'server error') + '. Reload to retry.', 'text-danger fw-semibold');
                return;
            }
            applyResult(res.body, keys);
            refreshStatus();
            if (Object.keys(pending).length) flush();
        })
        .catch(function() {
            inFlight = false;
            setStatus('Network error \u2014 changes not saved.', 'text-danger fw-semibold');
        });
    }

    function find(item, student) {
        return gb.querySelector('input.gb-input[data-item="' + item + '"][data-student="' + student + '"]');
    }

    function applyResult(res, keys) {
        // Cells the server refused were NOT written, so they must stay dirty
        // and red. Keep `committed` pointing at the last value the server
        // actually accepted, so Esc still restores a good value.
        var refused = {};
        (res.rejected || []).forEach(function(rj) { refused[rj.item_id + ':' + rj.student_id] = true; });

        keys.forEach(function(k) {
            var p = k.split(':');
            var inp = find(p[0], p[1]);
            if (!inp) return;
            if (refused[k]) {
                inp.classList.add('cell-over');
                inp.classList.add('cell-dirty');
                return;
            }
            committed[k] = inp.value;
            inp.classList.remove('cell-dirty');
            inp.classList.remove('cell-over');
        });

        // 2. patch PS / IG / TG / descriptor for every touched student
        Object.keys(res.rows || {}).forEach(function(sid) {
            var row = res.rows[sid];
            gb.querySelectorAll('input.gb-input[data-student="' + sid + '"]').forEach(function(inp) {
                var tr = inp.closest('tr');
                if (!tr) return;
                Object.keys(row.ps).forEach(function(catId) {
                    setCell(tr.querySelector('.gb-ps[data-ps="' + catId + '"]'), row.ps[catId]);
                });
                setCell(tr.querySelector('.gb-ig'), row.ig);
                setCell(tr.querySelector('.gb-tg'), row.tg);
                setDesc(tr.querySelector('.gb-desc'), row.desc);
            });
        });

        // 3. patch the class average row
        var a = res.averages || {};
        Object.keys(a.items || {}).forEach(function(itemId) {
            setCell(gb.querySelector('[data-avg-item="' + itemId + '"]'), a.items[itemId]);
        });
        Object.keys(a.ps || {}).forEach(function(catId) {
            setCell(gb.querySelector('[data-avg-ps="' + catId + '"]'), a.ps[catId]);
        });
        if (a.grades) {
            setCell(gb.querySelector('.gb-avg-ig'), a.grades.ig);
            setCell(gb.querySelector('.gb-avg-tg'), a.grades.tg);
        }
    }

    function setCell(el, v) {
        if (!el) return;
        el.innerHTML = (v === null || v === undefined || v === '') ? '&mdash;' : v;
    }
    function setDesc(el, label) {
        if (!el) return;
        el.innerHTML = label ? '<span class="badge bg-primary">' + esc(label) + '</span>' : '&mdash;';
    }
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ================================================================
    // RANGE SELECTION
    // Shift+arrows extends a block from the anchor cell, like a
    // spreadsheet. The block drives copy, paste, fill-down and clear.
    // ================================================================
    var anchor = null;    // {r, c} where the drag/extend started
    var selRange = null;  // normalised {r1, c1, r2, c2}
    var extending = false; // true while Shift+arrows is growing a range

    function cellAt(r, c) { return (rows[r] && rows[r][c]) ? rows[r][c] : null; }

    function lastRow() {
        for (var r = rows.length - 1; r >= 0; r--) if (rows[r] && rows[r].length) return r;
        return 0;
    }
    function widestCol() {
        return Math.max.apply(null, rows.map(function(r) { return r ? r.length : 0; }));
    }

    function paintSel() {
        gb.querySelectorAll('td.gb-sel').forEach(function(td) { td.classList.remove('gb-sel'); });
        if (!selRange) return;
        for (var r = selRange.r1; r <= selRange.r2; r++) {
            for (var c = selRange.c1; c <= selRange.c2; c++) {
                var i = cellAt(r, c);
                if (i) i.parentNode.classList.add('gb-sel');
            }
        }
    }

    function setSel(r1, c1, r2, c2) {
        selRange = {
            r1: Math.min(r1, r2), r2: Math.max(r1, r2),
            c1: Math.min(c1, c2), c2: Math.max(c1, c2)
        };
        if (selRange.r1 === selRange.r2 && selRange.c1 === selRange.c2) selRange = null;
        paintSel();
    }
    function clearSel() { selRange = null; anchor = null; extending = false; paintSel(); }

    function selectedInputs() {
        if (selRange) {
            var out = [];
            for (var r = selRange.r1; r <= selRange.r2; r++) {
                for (var c = selRange.c1; c <= selRange.c2; c++) {
                    var i = cellAt(r, c);
                    if (i) out.push(i);
                }
            }
            return out;
        }
        return activeCell ? [activeCell] : [];
    }

    // ================================================================
    // UNDO
    // ================================================================
    var undoStack = [];
    var undoBtn = document.getElementById('gbUndo');

    function snapshot(list) {
        var snap = {};
        list.forEach(function(i) { snap[key(i)] = i.value; });
        return snap;
    }
    function pushUndo(snap, label) {
        if (!Object.keys(snap).length) return;
        undoStack.push({ cells: snap, label: label });
        while (undoStack.length > 80) undoStack.shift();
        if (undoBtn) undoBtn.disabled = false;
    }
    function updateUndoBtn() { if (undoBtn) undoBtn.disabled = undoStack.length === 0; }

    // Apply {key: value} to cells as one undoable, one-request operation.
    function applyValues(map, label) {
        var list = [];
        Object.keys(map).forEach(function(k) {
            var p = k.split(':');
            var i = find(p[0], p[1]);
            if (i) list.push(i);
        });
        if (!list.length) return;
        pushUndo(snapshot(list), label);
        list.forEach(function(i) {
            i.value = map[key(i)];
            checkOver(i);
            mark(i);
        });
        setStatus(label + ' \u2014 ' + list.length + ' cell(s), saving\u2026', 'text-muted', 4000);
        clearTimeout(timer);
        flush();
        refreshStatus();
    }

    // ================================================================
    // EVENTS
    // ================================================================
    function isCell(t) { return t && t.tagName === 'INPUT' && t.classList.contains('gb-input'); }

    gb.addEventListener('keydown', function(e) {
        var t = e.target;
        if (!isCell(t)) return;
        var r = +t.dataset.ri, c = +t.dataset.ci;
        var mod = e.ctrlKey || e.metaKey;
        var k = e.key;

        // ---- Ctrl+A : select the whole grid ----
        if (mod && (k === 'a' || k === 'A')) {
            e.preventDefault();
            setSel(0, 0, lastRow(), widestCol() - 1);
            return;
        }
        // ---- Ctrl+Z : undo ----
        if (mod && (k === 'z' || k === 'Z')) {
            e.preventDefault();
            var u = undoStack.pop();
            updateUndoBtn();
            if (!u) { setStatus('Nothing to undo.', 'text-muted', 3000); return; }
            Object.keys(u.cells).forEach(function(kk) {
                var p = kk.split(':');
                var i = find(p[0], p[1]);
                if (!i) return;
                i.value = u.cells[kk];
                i.classList.remove('cell-over');
                checkOver(i);
                mark(i);
            });
            var n = Object.keys(u.cells).length;
            setStatus('Undid ' + u.label.toLowerCase() + ' (' + n + ' cell' + (n === 1 ? '' : 's') + ').', 'text-muted', 4000);
            clearTimeout(timer);
            flush();
            refreshStatus();
            return;
        }
        // ---- Ctrl+D : fill the selection down from its first cell ----
        if (mod && (k === 'd' || k === 'D')) {
            e.preventDefault();
            var cells = selectedInputs();
            if (cells.length < 2) { setStatus('Select a range first (Shift+arrows), then Ctrl+D fills it down.', 'text-muted', 4000); return; }
            var src = cells[0], fill = {}, hit = 0;
            cells.slice(1).forEach(function(i) {
                if (i.value !== src.value) { fill[key(i)] = src.value; hit++; }
            });
            if (hit) applyValues(fill, 'Fill down');
            else setStatus('Already filled.', 'text-muted', 3000);
            return;
        }
        // ---- Delete : clear the selection (score becomes unrecorded) ----
        if (k === 'Delete') {
            e.preventDefault();
            var clear = {}, n = 0;
            selectedInputs().forEach(function(i) {
                if (i.value !== '') { clear[key(i)] = ''; n++; }
            });
            if (n) applyValues(clear, 'Cleared');
            return;
        }
        // ---- Escape : drop the selection, then revert the cell ----
        if (k === 'Escape') {
            e.preventDefault();
            if (selRange) { clearSel(); setStatus('Selection cleared.', 'text-muted', 2000); return; }
            var kk = key(t);
            t.value = committed[kk] !== undefined ? committed[kk] : '';
            t.classList.remove('cell-dirty', 'cell-over');
            delete pending[kk];
            refreshStatus();
            return;
        }

        // ---- arrows: Shift extends the selection ----
        if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'ArrowLeft' || k === 'ArrowRight') {
            e.preventDefault();
            checkOver(t); mark(t); flush();
            var dr = k === 'ArrowDown' ? 1 : (k === 'ArrowUp' ? -1 : 0);
            var dc = k === 'ArrowRight' ? 1 : (k === 'ArrowLeft' ? -1 : 0);
            if (e.shiftKey) {
                // Keep the anchor for the whole drag: the focus we move below
                // fires focusin, which must not re-seed it or the range collapses.
                if (!extending || !anchor) { anchor = { r: r, c: c }; }
                extending = true;
                var nr = Math.max(0, Math.min(lastRow(), anchor.r + dr));
                var nc = Math.max(0, Math.min(widestCol() - 1, anchor.c + dc));
                setSel(anchor.r, anchor.c, nr, nc);
                var keep = cellAt(selRange ? selRange.r2 : nr, selRange ? selRange.c2 : nc) || t;
                keep.focus();
                setCursor(keep);
            } else {
                clearSel();
                focus(r + dr, c + dc);
            }
            return;
        }
        // ---- Enter / Tab move past the selection ----
        if (k === 'Enter') {
            e.preventDefault();
            checkOver(t); mark(t); flush();
            var er = selRange ? selRange.r2 : r;
            var ec = selRange ? selRange.c2 : c;
            clearSel();
            focus(e.shiftKey ? er - 1 : er + 1, ec);
            return;
        }
        if (k === 'Tab') {
            checkOver(t); mark(t); flush();
            return; // browser moves focus naturally (it skips PS/IG/TG cells)
        }
        // ---- Home / End ----
        if (mod && (k === 'Home' || k === 'End')) {
            e.preventDefault();
            clearSel();
            focus(k === 'Home' ? 0 : lastRow(), 0);
            return;
        }
    });

    // ================================================================
    // COPY : selection -> clipboard as TSV (pastes straight into Excel,
    // and straight back into the grid).
    // ================================================================
    gb.addEventListener('copy', function(e) {
        var t = e.target;
        if (!isCell(t)) return;
        var cells = selectedInputs();
        if (!cells.length) return;
        var byRow = {};
        cells.forEach(function(i) {
            var r = +i.dataset.ri, c = +i.dataset.ci;
            if (!byRow[r]) byRow[r] = {};
            byRow[r][c] = i.value;
        });
        var lines = Object.keys(byRow).map(function(r) {
            var cs = Object.keys(byRow[r]).map(Number).sort(function(a, b) { return a - b; });
            return cs.map(function(c) { return byRow[r][c]; }).join('\t');
        });
        e.clipboardData.setData('text/plain', lines.join('\n'));
        e.preventDefault();
        setStatus('Copied ' + cells.length + ' cell(s) \u2014 paste into Excel or back here.', 'text-muted', 3000);
    });

    // ================================================================
    // PASTE : TSV from Excel (or from a grid copy) fills down/across
    // from the focused cell. Values outside the activity maximum are
    // skipped and reported, never silently written.
    // ================================================================
    gb.addEventListener('paste', function(e) {
        var t = e.target;
        if (!isCell(t)) return;
        var text = (e.clipboardData || window.clipboardData).getData('text');
        if (text === '' || text === null) return;
        e.preventDefault();

        var startR = +t.dataset.ri, startC = +t.dataset.ci;
        var lines = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '').split('\n');
        var map = {}, filled = 0, skipped = 0, offGrid = 0;

        for (var i = 0; i < lines.length; i++) {
            var parts = lines[i].split('\t');
            for (var j = 0; j < parts.length; j++) {
                var inp = cellAt(startR + i, startC + j);
                if (!inp) { offGrid++; continue; }
                var v = parts[j].trim();
                if (v === inp.value) continue;
                if (v !== '') {
                    var n = parseFloat(v);
                    var mx = parseFloat(inp.max);
                    if (isNaN(n) || n < 0 || (!isNaN(mx) && n > mx)) { skipped++; continue; }
                    v = String(n);
                }
                map[key(inp)] = v;
                filled++;
            }
        }
        if (filled) {
            applyValues(map, 'Pasted');
            if (skipped) {
                setStatus('Pasted ' + filled + ' cell(s), skipped ' + skipped + ' outside the activity maximum.', 'text-danger fw-semibold', 6000);
            }
        } else if (skipped) {
            setStatus('Nothing pasted \u2014 ' + skipped + ' value(s) are outside the activity maximum.', 'text-danger fw-semibold', 6000);
        } else if (offGrid) {
            setStatus('Nothing pasted \u2014 the copied block does not fit in the grid.', 'text-muted', 4000);
        } else {
            setStatus('Nothing to paste.', 'text-muted', 3000);
        }
    });

    // ---- typing by hand drops the range, like a spreadsheet ----
    gb.addEventListener('input', function(e) {
        var t = e.target;
        if (!isCell(t)) return;
        if (selRange) clearSel();
        if (statusTimer) { clearTimeout(statusTimer); statusTimer = null; }
        t.classList.add('cell-dirty');
        checkOver(t);
        mark(t);
        refreshStatus();
        clearTimeout(timer);
        timer = setTimeout(function() { flush(); }, 1200);
    });

    // Commit when the teacher tabs or clicks away
    gb.addEventListener('focusout', function(e) {
        var t = e.target;
        if (!isCell(t)) return;
        if (!isDirty(t)) return;
        checkOver(t); mark(t);
        clearTimeout(timer);
        timer = setTimeout(function() { flush(); }, 250);
    });

    gb.addEventListener('focusin', function(e) {
        var t = e.target;
        if (!isCell(t)) return;
        setCursor(t);
        // Do not re-seed the anchor mid-drag, or Shift+arrows collapses to one cell.
        if (!extending) anchor = { r: +t.dataset.ri, c: +t.dataset.ci };
        clearTimeout(timer);
        timer = setTimeout(function() { try { t.select(); } catch (err) {} }, 0);
    });

    // Click another cell without dragging: drop any old range
    gb.addEventListener('mousedown', function(e) {
        var t = e.target;
        if (isCell(t)) return;
        if (!gb.contains(t)) return;
        clearSel();
    });

    if (undoBtn) undoBtn.addEventListener('click', function() {
        gb.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true }));
    });

    // Explicit "Save All Scores" posts the whole grid as a fallback; clear the
    // dirty flags first so the unload guard does not fire on the redirect.
    if (form) form.addEventListener('submit', function() {
        clearTimeout(timer);
        gb.querySelectorAll('.cell-dirty').forEach(function(el) { el.classList.remove('cell-dirty'); });
        window.onbeforeunload = null;
    });

    window.addEventListener('beforeunload', function(e) {
        if (inFlight || countDirty() || Object.keys(pending).length) { e.preventDefault(); e.returnValue = ''; }
    });

    wireCollapse();
    refreshStatus();
    updateUndoBtn();

    function wireCollapse() {
        var btns = gb.querySelectorAll('.gb-collapse');
        btns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var cat = btn.dataset.cat;
                var on = gb.classList.toggle('collapse-' + cat);
                var th = btn.closest('th');
                if (th) th.classList.toggle('collapsed', on);
                btn.setAttribute('aria-expanded', on ? 'false' : 'true');
                persistCollapse();
            });
        });
        var exp = document.getElementById('gbExpandAll');
        var col = document.getElementById('gbCollapseAll');
        if (exp) exp.addEventListener('click', function() { setAll(false); });
        if (col) col.addEventListener('click', function() { setAll(true); });
        function setAll(on) {
            btns.forEach(function(btn) {
                var cat = btn.dataset.cat;
                gb.classList.toggle('collapse-' + cat, on);
                var th = btn.closest('th');
                if (th) th.classList.toggle('collapsed', on);
                btn.setAttribute('aria-expanded', on ? 'false' : 'true');
            });
            persistCollapse();
        }
        function persistCollapse() {
            var on = [];
            btns.forEach(function(btn) {
                if (gb.classList.contains('collapse-' + btn.dataset.cat)) on.push(btn.dataset.cat);
            });
            var url = new URL(window.location.href);
            if (on.length) url.searchParams.set('collapse', on.join(','));
            else url.searchParams.delete('collapse');
            window.history.replaceState({}, '', url.toString());
        }
    }
}
