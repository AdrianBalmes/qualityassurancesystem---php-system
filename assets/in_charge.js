/**
 * The In Charge cell: who is carrying out a recommendation.
 *
 * One implementation for the admin grid and for an office's own dashboard. The
 * cell markup comes from render_in_charge_cell() in in_charge.php, or from
 * InCharge.cellHtml() for a row added without reloading the page; either way
 * the chosen names live in the cell's data-json and are drawn from there.
 *
 * A cell's data-mode says how much of it the viewer may change -- 'full',
 * 'staff' (their own office's people only) or 'view' -- and in_charge.php
 * explains when each applies. The server enforces the same rule; this only
 * keeps the page from offering what would be refused.
 *
 * Usage:
 *   InCharge.configure(<from in_charge_options()>);
 *   InCharge.init(document);                 // draw the tags
 *   InCharge.onChange(function(cell, names){ ... });   // page decides when to save
 */
(function(){
    'use strict';

    var staffByOffice = {};
    var allOffices = [];
    var changeHandler = null;
    var panel = null;
    var panelCell = null;

    function escapeHtml(text){
        return String(text).replace(/[&<>"']/g, function(ch){
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch];
        });
    }

    function parse(raw){
        try {
            var list = JSON.parse(raw || '[]');
            if(Array.isArray(list)){
                return list.filter(function(v){ return typeof v === 'string' && v.trim() !== ''; });
            }
        } catch(e){}
        // A plain string is a row saved before this became a list.
        return raw && raw.trim() !== '' ? [raw.trim()] : [];
    }

    function names(cell){
        return parse(cell.getAttribute('data-json'));
    }

    function cellMode(cell){
        return cell.getAttribute('data-mode') || 'view';
    }

    function viewerOffice(cell){
        return cell.getAttribute('data-viewer-office') || '';
    }

    function staffOf(office){
        return staffByOffice[office] || [];
    }

    function staffNamesOf(office){
        return staffOf(office).map(function(person){ return person.name; });
    }

    function isOfficeName(name){
        return allOffices.indexOf(name) !== -1;
    }

    /** Whether this viewer may tick or untick one particular name. */
    function canToggle(cell, name){
        var mode = cellMode(cell);
        if(mode === 'full'){ return true; }
        if(mode !== 'staff'){ return false; }
        return staffNamesOf(viewerOffice(cell)).indexOf(name) !== -1;
    }

    function renderTags(cell){
        var list = names(cell);
        var wrap = cell.querySelector('.incharge-tags');
        if(!wrap){ return; }

        wrap.innerHTML = list.length
            ? list.map(function(name, idx){
                var removable = canToggle(cell, name);
                return "<span class='incharge-tag" + (removable ? "" : " incharge-tag-plain") + "'>" + escapeHtml(name) +
                    (removable ? "<button type='button' class='incharge-remove' data-index='" + idx + "' title='Remove'>&times;</button>" : "") +
                "</span>";
              }).join('')
            : "<span class='incharge-empty'>Not yet assigned</span>";
        cell.setAttribute('data-json', JSON.stringify(list));
    }

    function setNames(cell, list){
        cell.setAttribute('data-json', JSON.stringify(list));
        renderTags(cell);
        if(changeHandler){ changeHandler(cell, list); }
    }

    function addName(cell, name){
        name = (name || '').trim();
        if(!name || name.length > 100){ return false; }
        var list = names(cell);
        if(list.indexOf(name) !== -1){ return false; }
        list.push(name);
        setNames(cell, list);
        return true;
    }

    function removeName(cell, name){
        var list = names(cell);
        var at = list.indexOf(name);
        if(at === -1){ return; }
        list.splice(at, 1);
        setNames(cell, list);
    }

    function removeAt(cell, index){
        var list = names(cell);
        if(index < 0 || index >= list.length){ return; }
        setNames(cell, list.filter(function(_, i){ return i !== index; }));
    }

    function optionHtml(name, sub, chosen, enabled){
        return "<label class='incharge-option" + (enabled ? "" : " is-locked") + "'>" +
            "<input type='checkbox' value=\"" + escapeHtml(name) + "\"" +
                (chosen.indexOf(name) !== -1 ? ' checked' : '') +
                (enabled ? '' : ' disabled') + ">" +
            "<span class='incharge-option-text'><strong>" + escapeHtml(name) + "</strong>" +
            (sub ? "<small>" + escapeHtml(sub) + "</small>" : "") +
        "</span></label>";
    }

    function staffGroupHtml(office, chosen, cell, listed){
        var people = staffOf(office);
        if(!people.length){
            return "<div class='incharge-group'>" + escapeHtml(office) + " staff</div>" +
                "<div class='incharge-note'>No approved accounts in " + escapeHtml(office) + " yet.</div>";
        }

        var html = "<div class='incharge-group'>" + escapeHtml(office) + " staff</div>";
        people.forEach(function(person){
            listed[person.name] = true;
            html += optionHtml(person.name, person.username, chosen, canToggle(cell, person.name));
        });
        return html;
    }

    /**
     * Which offices' staff the list shows: the office the recommendation
     * belongs to, every office put in charge of it, and every office with
     * somebody already ticked. An office managing its own people on another
     * office's recommendation sees only its own.
     */
    function staffOfficesFor(cell, chosen){
        var wanted = [];
        // An office with no accounts still gets a group: its note is the
        // answer to why nobody there can be ticked.
        function want(office){
            if(office && wanted.indexOf(office) === -1){ wanted.push(office); }
        }

        if(cellMode(cell) === 'staff'){
            want(viewerOffice(cell));
            return wanted;
        }

        want(cell.getAttribute('data-office') || '');
        chosen.forEach(function(name){ if(isOfficeName(name)){ want(name); } });
        Object.keys(staffByOffice).forEach(function(office){
            if(staffNamesOf(office).some(function(name){ return chosen.indexOf(name) !== -1; })){
                want(office);
            }
        });
        return wanted;
    }

    function panelHtml(cell){
        var chosen = names(cell);
        var mode = cellMode(cell);
        var owningOffice = cell.getAttribute('data-office') || '';
        var listed = {};
        var body = '';

        staffOfficesFor(cell, chosen).forEach(function(office){
            body += staffGroupHtml(office, chosen, cell, listed);
        });

        if(mode === 'full'){
            // An office ticked here also puts the recommendation on that
            // office's own dashboard, which is how work is handed over -- and
            // once ticked, its staff appear as a group of their own above.
            body += "<div class='incharge-group'>Other offices</div>";
            allOffices.forEach(function(office){
                if(office === owningOffice){ return; }
                listed[office] = true;
                body += optionHtml(office, 'Whole office', chosen, true);
            });
        }

        var rest = chosen.filter(function(name){ return !listed[name]; });
        if(rest.length){
            body += "<div class='incharge-group'>" + (mode === 'full' ? 'Typed in' : 'Already assigned') + "</div>";
            rest.forEach(function(name){
                body += optionHtml(name, isOfficeName(name) ? 'Whole office' : 'Not an account',
                                   chosen, canToggle(cell, name));
            });
        }

        var foot = mode === 'full'
            ? "<span class='incharge-foot-label'>Not on the list? Type a name</span>" +
              "<div class='incharge-person-row'>" +
                  "<input type='text' class='incharge-person-input' placeholder='e.g. Ms. Reyes' maxlength='100'>" +
                  "<button type='button' class='incharge-add-btn' title='Add'><i class='bi bi-plus-lg'></i></button>" +
              "</div>"
            : "<span class='incharge-foot-label'>You can assign your own office's staff</span>";

        return "<div class='incharge-panel-head'>Who is in charge?</div>" +
            "<div class='incharge-panel-body'>" + body + "</div>" +
            "<div class='incharge-panel-foot'>" + foot +
                "<button type='button' class='incharge-done-btn'>Done</button>" +
            "</div>";
    }

    function positionPanel(button){
        var rect = button.getBoundingClientRect();
        var width = panel.offsetWidth;
        var height = panel.offsetHeight;

        // Below the button by default, above it when there is no room, and
        // always clamped inside the viewport -- the button can be half
        // scrolled off, which would otherwise put the panel off-screen.
        var top = rect.bottom + 6;
        if(top + height > window.innerHeight - 12){
            top = rect.top - height - 6;
        }
        panel.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)) + 'px';
        panel.style.top = Math.max(12, Math.min(top, window.innerHeight - height - 12)) + 'px';
    }

    function openPanel(cell, button){
        closePanel();
        panel = document.createElement('div');
        panel.className = 'incharge-panel';
        panel.innerHTML = panelHtml(cell);
        document.body.appendChild(panel);
        panelCell = cell;
        panel.anchorButton = button;
        positionPanel(button);
    }

    function closePanel(){
        if(panel && panel.parentNode){ panel.parentNode.removeChild(panel); }
        panel = null;
        panelCell = null;
    }

    /** Tick boxes to match the cell after a change made outside the list. */
    function syncPanelChecks(){
        if(!panel || !panelCell){ return; }
        var chosen = names(panelCell);
        panel.querySelectorAll("input[type='checkbox']").forEach(function(box){
            box.checked = chosen.indexOf(box.value) !== -1;
        });
    }

    /**
     * Rebuild the list, keeping the reader's place. Needed whenever the groups
     * themselves change -- ticking an office reveals its staff, and a name
     * just typed in belongs under "Typed in".
     */
    function refreshPanel(focusInput){
        if(!panel || !panelCell){ return; }
        var button = panel.anchorButton;
        var body = panel.querySelector('.incharge-panel-body');
        var scrolled = body ? body.scrollTop : 0;

        panel.innerHTML = panelHtml(panelCell);
        positionPanel(button);

        body = panel.querySelector('.incharge-panel-body');
        if(body){ body.scrollTop = scrolled; }
        if(focusInput){
            var input = panel.querySelector('.incharge-person-input');
            if(input){ input.focus(); }
        }
    }

    /**
     * Dragging the list's scrollbar and letting go anywhere outside the panel
     * reports the click against a shared ancestor, not the panel -- which read
     * as "clicked away" and shut the panel mid-scroll. What matters is where
     * the press started, so that is what is remembered.
     */
    var pressStartedInside = false;
    document.addEventListener('mousedown', function(e){
        pressStartedInside = !!(panel && e.target.nodeType === 1 && panel.contains(e.target));
    }, true);

    document.addEventListener('click', function(e){
        var pickBtn = e.target.closest('.incharge-pick-btn');
        if(pickBtn){
            e.preventDefault();
            var pickCell = pickBtn.closest('.incharge-cell');
            if(panelCell === pickCell){ closePanel(); return; }
            openPanel(pickCell, pickBtn);
            return;
        }

        var removeBtn = e.target.closest('.incharge-remove');
        if(removeBtn){
            e.preventDefault();
            removeAt(removeBtn.closest('.incharge-cell'), parseInt(removeBtn.getAttribute('data-index'), 10));
            if(panel){ refreshPanel(false); }
            return;
        }

        if(panel && panel.contains(e.target)){
            if(e.target.closest('.incharge-add-btn')){
                var addInput = panel.querySelector('.incharge-person-input');
                var added = addName(panelCell, addInput.value);
                addInput.value = '';
                if(added){ refreshPanel(true); } else { addInput.focus(); }
            } else if(e.target.closest('.incharge-done-btn')){
                closePanel();
            }
            return;
        }

        if(!pressStartedInside){ closePanel(); }
    });

    document.addEventListener('change', function(e){
        if(!panel || !panelCell || !panel.contains(e.target)){ return; }
        if(e.target.type !== 'checkbox'){ return; }

        var value = e.target.value;
        if(!canToggle(panelCell, value)){
            syncPanelChecks();
            return;
        }

        if(e.target.checked){ addName(panelCell, value); } else { removeName(panelCell, value); }

        // Ticking an office brings a whole group of its staff into the list.
        if(isOfficeName(value)){ refreshPanel(false); } else { syncPanelChecks(); }
    });

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){ closePanel(); return; }
        if(e.key !== 'Enter' || !panel){ return; }
        var input = e.target.closest('.incharge-person-input');
        if(!input){ return; }
        e.preventDefault();
        var added = addName(panelCell, input.value);
        input.value = '';
        if(added){ refreshPanel(true); }
    });

    /**
     * The panel is anchored to a button that moves when the page or the grid
     * scrolls, so it follows along rather than being dismissed. It closes only
     * when its row is gone from the page -- scrolling is never a decision to
     * stop assigning.
     *
     * Scrolling the list inside the panel is not scrolling at all as far as
     * this is concerned: the listener is on capture, so it sees those events
     * too and has to ignore them.
     */
    function followAnchor(e){
        if(!panel){ return; }
        if(e && e.target && e.target.nodeType === 1 && panel.contains(e.target)){ return; }

        var button = panel.anchorButton;
        if(!button || !button.isConnected){ closePanel(); return; }
        positionPanel(button);
    }

    window.addEventListener('resize', followAnchor);
    window.addEventListener('scroll', followAnchor, true);

    window.InCharge = {
        configure: function(options){
            options = options || {};
            staffByOffice = options.staff || {};
            allOffices = options.offices || [];
        },
        init: function(root){
            (root || document).querySelectorAll('.incharge-cell').forEach(renderTags);
        },
        onChange: function(fn){ changeHandler = fn; },
        names: names,
        close: closePanel,
        cellHtml: function(office, mode){
            mode = mode || 'view';
            var assign = mode === 'view' ? "" :
                "<div class='incharge-add-row'>" +
                    "<button type='button' class='incharge-pick-btn'><i class='bi bi-check2-square'></i> Assign</button>" +
                "</div>";
            return "<div class='incharge-cell' data-field='in_charge' data-office=\"" + escapeHtml(office) + "\"" +
                " data-mode='" + escapeHtml(mode) + "' data-viewer-office='' data-json='[]'>" +
                "<div class='incharge-tags'></div>" + assign +
            "</div>";
        }
    };
})();
