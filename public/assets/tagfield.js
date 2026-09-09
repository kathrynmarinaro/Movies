/* The tag picker: a row of chips plus an Add button that raises the sheet.
 *
 * ---------------------------------------------------------------------------
 * A CLOSED LIST, NOT FREE TEXT — WHICH IS THE OPPOSITE OF THE GALLERY'S.
 * ---------------------------------------------------------------------------
 *
 * The Inspiration Gallery's tagfield is a comma-separated text input with
 * autocomplete, because its vocabulary is open and grows as you use it. This
 * one is a picker over a seeded, editable list, and it is deliberately NOT a
 * text input:
 *
 *   · Free text drifts. "Bsmt", "basement" and "Basement" become three rooms
 *     that never match each other, and no filter can put them back together.
 *   · A house has a known set of rooms. Typing one is slower than tapping it,
 *     on the device this app is actually used on.
 *   · Nothing here lowercases or normalizes a name. "Ext Studio" is stored
 *     exactly as typed (CLAUDE.md), so a client-side normalizer would be a
 *     second, disagreeing opinion about what a tag is called.
 *
 * New tags are added on the Rooms & Tags screen, not in passing while filling
 * in a form. That is a deliberate speed bump: a tag created mid-form is how you
 * end up with "Kitchen " and "kitchen".
 *
 * ---------------------------------------------------------------------------
 * THE <select multiple> IS THE REAL CONTROL.
 * ---------------------------------------------------------------------------
 *
 * Every field is server-rendered as a plain multi-select inside the root, and
 * this module hides it and drives it. So:
 *
 *   · With no JS, the form still works — an ugly multi-select, but a real one
 *     that posts real values.
 *   · There is no parallel state to keep in sync. The chips ARE a rendering of
 *     the select's options, and the form posts whatever the select says.
 *   · A caller that just wants the values on submit needs no JS at all.
 *
 * GENERIC, like the other shared modules: it takes a root and knows nothing
ate

/** Accept a selector or an element, like every other module here. */
function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/* One sheet at a time, module-global — the same rule menu.js and swipe.js use.
   Two open sheets would stack their backdrops and the second Cancel would
   appear to do nothing. */
let openSheet = null;

export function closePicker() {
  if (openSheet !== null) {
    openSheet.remove();
    openSheet = null;
  }
}

/** Group label for a kind. Mirrors tag_kind_label() in lib/tags.php. */
const KIND_LABELS = {
  location: 'Rooms & areas',
  category: 'Systems',
  work_type: 'Work types',
};

/**
 * Read the options out of the native select, grouped by their data-kind.
 *
 * The DOM is the source of truth rather than a JS array passed in, so a
 * re-render of the server's markup is picked up for free and there is nothing
 * to invalidate.
 */
function readOptions(select) {
  const groups = new Map();
  Array.from(select.options).forEach((option) => {
    const kind = option.dataset.kind || 'category';
    if (!groups.has(kind)) { groups.set(kind, []); }
    groups.get(kind).push(option);
  });
  return groups;
}

function selectedOptions(select) {
  return Array.from(select.options).filter((option) => option.selected);
}

/**
 * Attach the picker to a root containing a <select multiple>.
 *
 *   <div class="tagfield" id="entry-tags">
 *     <select multiple name="tags[]" class="sr-only">
 *       <option value="3" data-kind="location" selected>Kitchen</option>
 *       …
 *     </select>
 *   </div>
 *
 *   const field = attachTagField('#entry-tags', {
 *     onChange: (ids) => saveDraft(ids),
 *   });
 *
 * @returns {{ value: () => number[], set: (ids: number[]) => void, detach: () => void }}
 *          `detach` is a no-op when there was nothing to attach to, so a caller
 *          never has to check before calling it.
 */
export function attachTagField(root, opts = {}) {
  const el = resolveRoot(root);
  const noop = { value: () => [], set: () => {}, detach: () => {} };
  if (!el) { return noop; }

  const select = el.querySelector('select');
  if (!select) { return noop; }

  const {
    onChange = null,
    addLabel = 'Add tag',
    emptyLabel = 'No tags yet',
  } = opts;

  /* Hidden from sight but still focusable and still submitted. Not `hidden`
     and not display:none — a select removed from the accessibility tree takes
     its values out of the form's tab order, and this is the only real control
     here. */
  select.classList.add('sr-only');

  const chipRow = document.createElement('div');
  chipRow.className = 'tagfield';

  const addBtn = document.createElement('button');
  addBtn.type = 'button';                    // never submits the form it is in
  addBtn.className = 'tagfield-add';
  addBtn.textContent = '+ ' + addLabel;

  el.appendChild(chipRow);
  el.appendChild(addBtn);

  function emit() {
    if (typeof onChange === 'function') {
      onChange(currentIds());
    }
  }

  function currentIds() {
    return selectedOptions(select).map((option) => Number(option.value));
  }

  function render() {
    chipRow.textContent = '';
    const chosen = selectedOptions(select);

    if (chosen.length === 0) {
      const empty = document.createElement('span');
      empty.className = 'hint';
      empty.textContent = emptyLabel;
      chipRow.appendChild(empty);
      return;
    }

    chosen.forEach((option) => {
      const chip = document.createElement('span');
      chip.className = 'chip is-static'
        + (option.dataset.kind === 'location' ? ' is-location' : '');
      /* textContent, not innerHTML — a tag name is user-entered text and this
         is the one place it re-enters the DOM after the server escaped it. */
      chip.textContent = option.textContent;

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'chip-x';
      remove.setAttribute('aria-label', 'Remove ' + option.textContent);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        option.selected = false;
        render();
        emit();
      });

      chip.appendChild(remove);
      chipRow.appendChild(chip);
    });
  }

  function openPicker() {
    /* Tapping Add while the sheet is open closes it rather than stacking a
       second one — menu.js's rule, for the same reason. */
    if (openSheet !== null) {
      closePicker();
      return;
    }

    const sheet = document.createElement('div');
    sheet.className = 'sheet';
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-label', addLabel);

    const panel = document.createElement('div');
    panel.className = 'sheet-panel';

    const groups = readOptions(select);
    let offered = 0;

    groups.forEach((options, kind) => {
      const heading = document.createElement('div');
      heading.className = 'sheet-group';
      heading.textContent = KIND_LABELS[kind] || kind;
      panel.appendChild(heading);

      options.forEach((option) => {
        const row = document.createElement('button');
        row.type = 'button';
        row.textContent = option.textContent;
        if (option.selected) {
          row.classList.add('is-current');
          row.setAttribute('aria-pressed', 'true');
        } else {
          row.setAttribute('aria-pressed', 'false');
        }

        /* The sheet STAYS OPEN on a tap. Picking three rooms is the normal
           case, and a sheet that closes after each one costs three trips. */
        row.addEventListener('click', () => {
          option.selected = !option.selected;
          row.classList.toggle('is-current', option.selected);
          row.setAttribute('aria-pressed', option.selected ? 'true' : 'false');
          render();
          emit();
        });

        panel.appendChild(row);
        offered++;
      });
    });

    if (offered === 0) {
      const none = document.createElement('div');
      none.className = 'sheet-group';
      none.textContent = 'Nothing to pick — add some on Rooms & Tags';
      panel.appendChild(none);
    }

    const done = document.createElement('button');
    done.type = 'button';
    done.className = 'sheet-cancel';
    done.textContent = 'Done';
    done.addEventListener('click', closePicker);
    panel.appendChild(done);

    /* Tapping the backdrop dismisses too, but Done is what people reach for:
       on a phone the backdrop above a bottom sheet is the part of the screen
       your hand is furthest from. */
    sheet.addEventListener('click', (event) => {
      if (event.target === sheet) { closePicker(); }
    });

    sheet.appendChild(panel);
    document.body.appendChild(sheet);
    openSheet = sheet;
  }

  addBtn.addEventListener('click', openPicker);
  render();

  return {
    value: currentIds,
    set(ids) {
      const wanted = new Set(ids.map(Number));
      Array.from(select.options).forEach((option) => {
        option.selected = wanted.has(Number(option.value));
      });
      render();
    },
    detach() {
      closePicker();
      addBtn.remove();
      chipRow.remove();
      select.classList.remove('sr-only');
    },
  };
}

/** Escape closes the picker, matching every other sheet in the app. */
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && openSheet !== null) { closePicker(); }
});
