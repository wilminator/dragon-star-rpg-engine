// The conversation dialog: tagged dialog text (dialog_text.js) typed out
// in a frame the fight dialog's size, with a Next button for ack tags and
// a selection list for question and switch tags. The inn and the store
// are its first users; tags only they understand (vend, inn, heal...)
// reach them through on_tag.
//
// options:
//   text         the tagged dialog text
//   title        the frame's title (optional)
//   on_tag(command, parts) -> Promise of a label to go to, or nothing
//   on_end()     the conversation finished (or was left with Escape)
//   delay        ms per character (render_dialog's 50)
//   parent, frame   as dialog_frame()
//   render       stands in for render_dialog (tests)
//
// Keys: Enter or Space for Next; arrows, Enter and Escape in the list of
// answers; Escape otherwise leaves the conversation.
//
// Needs dialogs.js, dialog_text.js and status_dialog.js (dialog_element).
var conversation_count = 0;

var ConversationDialog = class {
    constructor(options) {
        this.options = options || {};
        this.frame = null;
        this.element = null;
        this.closed = true;
        this.choices = null; // the SelectionList while a choice is asked
        this.answer = null;  // resolves the choice being asked
    }

    open() {
        var id = 'conversation' + (++conversation_count);
        var make_frame = this.options.frame || dialog_frame;
        this.frame = make_frame({title: this.options.title, parent: this.options.parent});
        this.element = this.frame.element;
        this.element.setAttribute('role', 'dialog');
        this.element.setAttribute('aria-label', this.options.title || 'Conversation');
        var body = this.frame.body;
        this.text = dialog_element('div', 'conversationtext');
        this.text.id = id + 'text';
        this.text.setAttribute('aria-live', 'polite');
        body.appendChild(this.text);
        this.list_box = dialog_element('div', 'conversationchoices');
        this.list_box.style.display = 'none';
        body.appendChild(this.list_box);
        var buttons = dialog_element('div', 'dialogbuttons');
        this.next = dialog_button(buttons, 'Next', function () {});
        this.next.id = id + 'next';
        this.next.style.visibility = 'hidden';
        body.appendChild(buttons);
        this.frame.show();
        this.closed = false;
        dialog_open(this);

        var self = this;
        var render = this.options.render || render_dialog;
        this.done = Promise.resolve(render(this.text.id, this.next.id, this.options.text, {
            ask: function (choice) { return self.ask(choice); },
            on_tag: this.options.on_tag,
            stopped: function () { return self.closed; },
            delay: this.options.delay,
        })).then(function () {
            if (!self.closed) self.finish();
        });
        return this.done;
    }

    // Shows a choice's prompts as a list; resolves with the label picked,
    // the cancel label, or null to go on.
    ask(choice) {
        var self = this;
        return new Promise(function (resolve) {
            if (self.closed) {
                resolve(null);
                return;
            }
            dialog_clear(self.list_box);
            var view = new SelectionListDomView(self.list_box, [{align: 'left'}], Math.min(4, Math.max(1, choice.prompts.length)));
            var list = new SelectionList({
                page_size: view.page_size,
                sounds: {select: 'select', cancel: 'cancel'},
                on_choose: function (row) { self._answered(row.value); },
                on_cancel: function () { self._answered(choice.cancel_label); },
            });
            view.bind(list);
            list.set_rows(choice.prompts.map(function (prompt) {
                return {value: prompt.label, cells: [prompt.text]};
            }));
            self.list_box.style.display = '';
            self.choices = list;
            self.answer = resolve;
            list.highlight(0);
        });
    }

    _answered(label) {
        var resolve = this.answer;
        this.answer = null;
        this.choices = null;
        this.list_box.style.display = 'none';
        dialog_clear(this.list_box);
        if (resolve) resolve(label === undefined ? null : label);
    }

    handle_key(key) {
        if (this.choices !== null) {
            return this.choices.handle_key(key) || (key === 'Escape' && (this.choices.cancel(), true));
        }
        if ((key === 'Enter' || key === ' ') && this.next.style.visibility !== 'hidden') {
            this.next.click();
            return true;
        }
        return false;
    }

    cancel() {
        this.finish();
    }

    finish() {
        if (this.closed) return;
        this.closed = true;
        if (this.answer) this._answered(null); // lets the renderer see it's stopped
        dialog_close(this);
        if (this.frame) this.frame.remove();
        if (this.options.on_end) this.options.on_end();
    }
};
