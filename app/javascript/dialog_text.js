/*
Dialog Text Rules:
- A tag is quoted with a pair of backticks.
- A block of text is delimited by either a line break or a label tag.
- An empty tag resolves to a displayed backtick.
- The first word of a text block is used to determine the type of text block and is followed by comma separated parameters.
    The following characters are recognized:
    label,name - the text block is a label for a dialog node. The label is used to identify the node in the dialog tree.
    jump,label - without comparison, go to the text block with the specified label.
    team, team_index, label - the following text is intended for the team whose teamid follows. 
        If the team index does not match, jump to label.        
    party, party_index, label - the following text is intended for the party index whose id follows in ascii text. 
        If the party index does not match, jump to label.
    question, prompt1, label1, prompt2, label2, ... - the following text is a question with multiple prompts. 
        Each prompt is followed by a label to jump to if that prompt is selected. If there is not a matching label 
        for the final prompt, it is treated as the label to jump to if the user cancels the dialog.
    vend, item1, price1, item2, price2, ... - the following text is a vendor menu with multiple items.
        Each item is followed by a price. If there is not a matching price for the final item, it is treated as the 
        label to jump to if the user cancels the dialog.
    hawk, margin, item1, price1, item2, price2, ... - the following text is a hawker menu where the player may sell items.
        The margin is the percentage of the price that the player will receive. If specified, items listed are bought at the 
        specified prices, unaffected by margin. If there is not a matching price for the final item, it is treated as the 
        label to jump to if the user cancels the dialog.
    inn, price, label - the following text is an inn menu where the player may rest. The price is the cost to rest. A 
        question will automatically prompt as Yes/No. Cancelling or selecting no will jump to label.
    switch, prompt1, label1, prompt2, label2, ... - the following text is a switch menu where the player may select from 
        multiple options. Each option is followed by a label that is jumped to if that option is selected. There is no default
        label for cancelling the dialog, so the text will fall throught to the next text block.
    services, prompt1, price1, label1, prompt2, price2, label2, ... - the following text is a services menu where the 
        player may select from multiple services. Each service is gated by the gold on hand. If the player does not have 
        enough gold, the service will be disabled. Each service is followed by a label that is jumped to if that service 
        is selected. There is no default label for cancelling the dialog, so the text will fall throught to the next text block.
    heal - The party is healed to full health.
    recharge - The party's magic points are restored to full.
    select_team - As a part of the dialog, select a team member. This will be the target of any dialog actions that only 
        affect one person.
    resurrect, price, label - The player is asked if they want to resurrect a dead team member for a specified price, 
        multiplied by level. Cancelling or selecting no will jump to label.
    cure, price, label - The player is asked if they want to cure a diseased team member for a specified price. Cancelling 
        or selecting no will jump to label.
    uncurse, price, label - The player is asked if they want to uncurse a diseased team member for a specified price. Cancelling 
        or selecting no will jump to label.
    pause, ms_wait - The dialog pauses for the time asked. No player interaction is needed to continue.
    sound, clip - Attempts to play the sound indicated by clip.
    music, clip - Attempts to play the abckground music indicated by clip.
    mute - Stops all exiting sounds and background music.
    ack - Requires the player to click next to continue.
    
Between text blocks, player interaction is always required. If player interaction was not needed to proceed from the prior block, 
  the player will need to click the next button to continue.
There is always an implicit 'end' label that will exit the dialog tree. It does not need to be defined.
Completing the last text block will also exit the dialog tree.
*/

function validate_tag_parts(tag_parts) {
    switch (tag_parts[0]) {
        case '':
            // An empty tag (two backticks) shows a backtick.
            if (tag_parts.length != 1)
                throw new Error(`an empty tag takes no parameters`);
            break;
        case 'label':
            if (tag_parts.length != 2) 
                throw new Error(`label tag requires two parts, ${tag_parts.length} present`);
            break;
        case 'sound':
            if (tag_parts.length != 2) 
                throw new Error(`sound tag requires two parts, ${tag_parts.length} present`);
            break;
        case 'music':
            if (tag_parts.length != 2) 
                throw new Error(`music tag requires two parts, ${tag_parts.length} present`);
            break;
        case 'jump':
            if (tag_parts.length != 2)
                throw new Error(`jump tag requires two parts, ${tag_parts.length} present`);
            break;
        case 'team':
            if (tag_parts.length != 3)
                throw new Error(`team tag requires three parts, ${tag_parts.length} present`);
            break;
        case 'party':
            if (tag_parts.length != 3)
                throw new Error(`party tag requires three parts, ${tag_parts.length} present`);
            break;
        case 'question':
            // prompt/label pairs, with an optional trailing cancel
            // label (making the total parameter count even or odd) --
            // "If there is not a matching label for the final prompt,
            // it is treated as the label to jump to if the user
            // cancels." At least one full prompt/label pair is
            // required regardless.
            if (tag_parts.length < 3)
                throw new Error(`question tag requires at least one prompt/label pair, ${tag_parts.length - 1} parameter(s) present`);
            break;
        case 'vend':
            // item/price pairs, same optional trailing cancel label as question.
            if (tag_parts.length < 3)
                throw new Error(`vend tag requires at least one item/price pair, ${tag_parts.length - 1} parameter(s) present`);
            break;
        case 'hawk':
            // A margin, then any item/price pairs bought at their own
            // prices ("If specified" -- the margin alone buys anything),
            // with the same optional trailing cancel label.
            if (tag_parts.length < 2 || !/^\d+$/.test(tag_parts[1]))
                throw new Error(`hawk tag requires a margin (a whole percent), ${tag_parts.length - 1} parameter(s) present`);
            break;
        case 'inn':
            if (tag_parts.length != 3)
                throw new Error(`inn tag requires three parts, ${tag_parts.length} present`);
            break;
        case 'switch':
            // Clean prompt/label pairs only -- "There is no default
            // label for cancelling", so no optional trailing cancel
            // label the way question/vend/hawk allow.
            if (tag_parts.length < 3 || (tag_parts.length - 1) % 2 != 0)
                throw new Error(`switch tag requires one or more complete prompt/label pairs, ${tag_parts.length - 1} parameter(s) present`);
            break;
        case 'services':
            // Clean prompt/price/label TRIPLETS -- same "no default
            // cancel label" rule as switch, but three parts per
            // service, not two.
            if (tag_parts.length < 4 || (tag_parts.length - 1) % 3 != 0)
                throw new Error(`services tag requires one or more complete prompt/price/label triplets, ${tag_parts.length - 1} parameter(s) present`);
            break;
        case 'mute':
        case 'ack':
        case 'heal':
        case 'recharge':
        case 'select_team':
            if (tag_parts.length != 1)
                throw new Error(`${tag_parts[0]} tag takes no parameters, ${tag_parts.length - 1} present`);
            break;
        case 'resurrect':
        case 'cure':
        case 'uncurse':
            if (tag_parts.length != 3)
                throw new Error(`${tag_parts[0]} tag requires two parts (price, label), ${tag_parts.length - 1} present`);
            break;
        case 'pause':
            if (tag_parts.length != 2)
                throw new Error(`pause tag requires one part (ms_wait), ${tag_parts.length - 1} present`);
            break;
        default:
            throw new Error(`unknown tag name ${tag_parts[0]}`);
    }
}

function generate_label_index(text) {
    // This function takes a string of text and marks the index positions for the interpreter.
    var labels = {};
    // Position index
    var pos = 0;
    while (pos < text.length) {
        const first_backtick = text.indexOf('`', pos);
        if (first_backtick == -1) {
            break;
        }
        const second_backtick = text.indexOf('`', first_backtick + 1);
        if (second_backtick == -1) {
            // This is a fatal syntax error. Throw.
            throw new Error(`Encountered an unpaired backtick at pos ${pos}`);
        }
        const tag = text.substring(first_backtick +1, second_backtick);
        const tag_parts = tag.split(',');
        validate_tag_parts(tag_parts);
        if (tag_parts[0] == 'label'){   
            labels[tag_parts[1]] = second_backtick + 1;
        }
        pos = second_backtick + 1;
    }
    //Return the processed text
    return labels;
}

function get_dialog_label_index(text_blocks, target_label) {
    // Returns the integer line that has the target label.
    // Returns text_block.length if no label matches for trigger end of script.
    for (index in text_blocks){
        if (text_blocks[index][0] == target_label) {
            return index;
        }
    }
    return text_blocks.length;
}

// Strips the first char_cols VISIBLE characters from the front of
// text_buffer (an HTML string), returning the remainder -- this is
// what makes the dialog box "scroll": once more rows have been
// written than fit on screen (char_rows), the oldest line is dropped
// from the top so the visible window stays within bounds. There's no
// explicit row-boundary marker to search for (rows aren't delimited
// by <br> or \n -- see fight_dialog_render_text's own row-padding
// logic above, which relies on the fixed-width monospace container
// for visual wrapping instead), so "one line" just means char_cols
// worth of characters, the same fixed unit every row is built from.
//
// HTML entities (&nbsp; etc, the only kind this buffer ever contains)
// count as ONE visible character each, matching the same "skip to the
// semicolon" convention fight_dialog_render_text's own cursor-location
// scan already uses.
function remove_top_dialog_line(environment) {
    var index = 0;
    var col = 0;
    while (col < environment.char_cols && index < environment.text_buffer.length) {
        const pos_char = environment.text_buffer[index];
        if (pos_char == "<") {
            var tag = '';
            ++index;
            while (environment.text_buffer[index] != '>') {
                tag += environment.text_buffer[index];
                ++index;
            }
            ++index;
            if (tag == 'br'){
                // This was a new line and is by definition the end of this line.
                break;
            }
            // HTML tags do not advance col.
            // Index is also where we need it.
            continue;
        }
        else if (pos_char == '&') {
            while (environment.text_buffer[index] != ';') ++index;
        }        
        ++index;
        ++col;
        
    }
    environment.text_buffer = environment.text_buffer.substring(index);
}

// Creates a fixed duration delay
const output_delay = ms => new Promise(resolve => setTimeout(resolve, ms));

// The choice a question or switch tag offers, once the text after it
// has been shown: {kind, prompts: [{text, label}], cancel_label}. A
// question's odd last part is its cancel label; a switch has none, and
// cancelling it (like a question without one) goes on to the next text.
function dialog_choice(command, parts) {
    var prompts = [];
    for (var i = 0; i + 1 < parts.length; i += 2) {
        prompts.push({text: parts[i], label: parts[i + 1]});
    }
    var cancel_label = (command === 'question' && parts.length % 2 === 1) ? parts[parts.length - 1] : null;
    return {kind: command, prompts: prompts, cancel_label: cancel_label};
}

// This renders a text line with interpretation to the designated window.
//
// options (all optional):
//   ask(choice) -> Promise of the label to go to, or null to go on:
//            shows a question or switch tag's prompts (dialog_choice())
//            after the text block that follows the tag. Without it the
//            prompts are skipped.
//   on_tag(command, parts) -> Promise of a label to go to, or nothing:
//            every tag this function doesn't act on itself (vend, inn,
//            heal...), for the screen that knows what they mean.
//   stopped() -> true to stop (the dialog was closed).
//   delay    ms per character (50).
async function render_dialog(dialogElementId, nextButtonId, text, options = {}){
    const labels = generate_label_index(text);
    const environment = new_render_text_environment(dialogElementId);
    environment.stopped = options.stopped || (() => false);
    const delay = options.delay ?? 50;
    const go_to = (label) => labels[label] ?? text.length;
    var pending = null; // a choice waiting for the end of its text block
    var pos = 0;
    while (pos < text.length && !environment.stopped()) {
        // Get the start of the next tag.
        const start_tag = text.indexOf('`', pos);
        var end = start_tag > -1 ? start_tag : text.length;
        if (pending !== null) {
            // A text block ends at a line break (or a tag, or the end).
            const newline = text.indexOf("\n", pos);
            if (newline > -1 && newline < end) end = newline + 1;
        }
        if (end > pos) {
            await dialog_render_text(environment, text.substring(pos, end), delay);
            pos = end;
        }
        if (pending !== null) {
            const choice = pending;
            pending = null;
            var label;
            if (choice.kind === 'vend' || choice.kind === 'hawk') {
                label = options.on_tag ? await options.on_tag(choice.kind, choice.parts) : null;
                if (label === null || label === undefined) label = choice.cancel_label;
            } else {
                label = options.ask ? await options.ask(choice) : null;
            }
            if (choice.kind === 'inn' && label === '') {
                label = options.on_tag ? await options.on_tag('inn', choice.parts) : null;
            }
            if (label !== null && label !== undefined && label !== '') pos = go_to(label);
            continue;
        }
        if (start_tag === -1) break;

        // Get the end of the tag, and process it.
        const end_tag = text.indexOf('`', start_tag + 1);
        const tag_string = text.substring(start_tag + 1, end_tag);
        pos = end_tag + 1;
        if (tag_string === '') {
            // An empty tag is a backtick to show.
            await dialog_render_text(environment, '`', delay);
            continue;
        }
        const tag_parts = tag_string.split(',');
        const command = tag_parts.shift();
        switch(command){
            case 'label':
                break;
            case 'jump':
                pos = go_to(tag_parts[0]);
                break;
            case 'team': {
                const [team_index, team_label] = tag_parts;
                // The player's own teams: several with an alliance.
                if (!(typeof teamids !== 'undefined' ? teamids : [teamid]).includes(parseInt(team_index)))
                    pos = go_to(team_label);
                break;
            }
            case 'party': {
                const [party_index, party_label] = tag_parts;
                if (player_party != parseInt(party_index))
                    pos = go_to(party_label);
                break;
            }
            case 'question':
            case 'switch':
                pending = dialog_choice(command, tag_parts);
                break;
            case 'vend':
            case 'hawk': {
                // A shop menu after the text that follows. on_tag(command,
                // parts) runs it and resolves when the player is done:
                // with a label to go to, or null for the tag's cancel
                // label (its last part, when the item/price pairs leave
                // one over).
                const pairs = command === 'hawk' ? tag_parts.slice(1) : tag_parts;
                pending = {kind: command, parts: tag_parts,
                    cancel_label: pairs.length % 2 === 1 ? pairs[pairs.length - 1] : null};
                break;
            }
            case 'inn': {
                // Yes or No after the text that follows; No (or cancel)
                // goes to the label, Yes asks on_tag('inn', parts) to
                // do the resting, which may send the text elsewhere.
                const [price, no_label] = tag_parts;
                pending = {kind: 'inn', prompts: [{text: 'Yes', label: ''}, {text: 'No', label: no_label}],
                    cancel_label: no_label, parts: [price, no_label]};
                break;
            }
            case 'ack':
                await dialog_wait_on_next_button(nextButtonId);
                break;
            case 'pause':
                await output_delay(parseInt(tag_parts[0]));
                break;
            case 'sound':
                play_sound(tag_parts[0]);
                break;
            case 'music':
                play_music(tag_parts[0]);
                break;
            case 'mute':
                stop_music();
                break;
            default:
                if (options.on_tag) {
                    const label = await options.on_tag(command, tag_parts);
                    if (label !== null && label !== undefined) pos = go_to(label);
                }
        }
    }
}

function new_render_text_environment(elementId){
    const dialog_element = document.getElementById(elementId);
    const computedStyles = window.getComputedStyle(dialog_element);
    const font_width = parseInt(computedStyles.fontSize);
    const font_height = parseInt(computedStyles.lineHeight);
    const char_cols = Math.floor(parseInt(computedStyles.width) / font_width);
    const char_rows = Math.floor(parseInt(computedStyles.height) / font_height) - 2;
    var text_buffer = dialog_element.innerHTML;
    // Locate the 'cursor'
    var row = 0, col = 0;
    for (var index = 0; index < text_buffer.length; ++index) {
        if (text_buffer[index] == '&') {
            while (text_buffer[index] != ';') ++index;
        }
        ++col;
        if (col == char_cols) {
            col = 0;
            ++row;
        }
    }
    return {
        'dialog_element': dialog_element,
        'computedStyles': computedStyles,
        'font_width':     font_width,
        'char_cols':      char_cols,
        'font_height':    font_height,
        'char_rows':      char_rows,
        'text_buffer':    text_buffer,
        'row':            row,
        'col':            col,
    };
}


async function dialog_render_text(environment, text, delay = 50) {
    // Begin rendering output.    
    for (var index = 0; index < text.length;) {
        var next_space = text.indexOf(' ', index);
        var newline = text.indexOf("\n", index);        
        if (newline != -1 && (next_space == -1 || newline < next_space)) {
            next_space = newline;
        }
        else if (next_space == -1) {
            next_space = text.length -1;
        }
        if (environment.col + next_space - index > environment.char_cols) {
            // The next space is beyond the last column, so this word will wrap.
            // If this is not col==0, then there is text with a trailing space at the end.
            // In that case, remove the last space, add non-blanking spaces to fill the 
            // row, then add the regular space back.
            if (environment.col > 0){
                environment.text_buffer = environment.text_buffer.substring(0,environment.text_buffer.length -1);
                while(environment.col < environment.char_cols) {
                    environment.text_buffer += '&nbsp;';
                    ++environment.col;
                }
                environment.text_buffer += ' ';
                environment.col = 0;
                ++environment.row;
                while (environment.row >= environment.char_rows) {
                    remove_top_dialog_line(environment);
                    --environment.row;
                }
            }
        }
        for (; index <= next_space; ++index) {
            if (text[index] == "\n") {
                // This is the last character, and it's a new line.
                environment.text_buffer += '<br>';
                environment.col = 0;
                ++environment.row;
            }
            else {
                environment.text_buffer += text[index];
                environment.col++;
                if (environment.col == environment.char_cols) {
                    environment.text_buffer += " ";
                    environment.col = 0;
                    ++environment.row;
                }
            }
            if (environment.col == 0) {
                while (environment.row >= environment.char_rows) {
                    remove_top_dialog_line(environment);
                    --environment.row;
                }
            }
            environment.dialog_element.innerHTML = environment.text_buffer;
            if (environment.stopped && environment.stopped()) return;
            if (delay > 0) await output_delay(delay);
        }
    }
}

function wait_for_click(button) {
  return new Promise((resolve) => {
    // Use { once: true } so the event listener cleans itself up automatically
    button.addEventListener('click', (event) => {
      resolve(event); 
    }, { once: true });
  });
}

async function dialog_wait_on_next_button(nextButtonId){
    const dialog_next_button = document.getElementById(nextButtonId);
    object_show(dialog_next_button);
    await wait_for_click(dialog_next_button);
    object_hide(dialog_next_button);
}