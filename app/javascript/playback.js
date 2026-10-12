var active_events=0;
var playback_queue=[];
var event_playback_callback=null;
// Promises registered by a [1] barrier while active_events>0, each
// resolved (and the whole list cleared) the moment active_events
// actually reaches 0 -- see finish_event() below. Not a fixed-length
// poll-and-give-up: however long the open events take, including a
// human reading a dialog and clicking a button, this waits that long.
var completion_resolvers=[];

function set_playback_queue(events,callback)
    {
    playback_queue=events;
    event_playback_callback=callback;
    event_playback();
    }

async function event_playback()
    {
    while(playback_queue.length>0)
        {
        var command=playback_queue.shift();
        switch(command[0])
            {
            case 0:
                var delay=call_function(command[1],command.slice(2));
                if (delay>0)
                    {
                    await new Promise((resolve) => setTimeout(resolve,delay));
                    }
                break;
            case 1:
                if(active_events>0)
                    {
                    await wait_for_open_events();
                    }
                break;
            }
        }
    if (event_playback_callback)
        event_playback_callback();
    }

// Resolves once active_events next reaches 0 -- registers this call's
// own resolve function so finish_event() can fire it later, rather
// than polling the counter on a timer.
function wait_for_open_events()
    {
    return new Promise((resolve) =>
        {
        completion_resolvers.push(resolve);
        });
    }

function call_function(func_name,args)
    {
    var index,cmdline,argline=[];
    if(func_name=="") return 0;
    for (index in args)
        argline.push("args["+index+"]");
    cmdline='(!'+func_name+')';
    funct=eval(cmdline);
    if (!funct)
        {
        cmdline='if('+func_name+') '+func_name+"("+argline.join(',')+")";
        cmdline=eval(cmdline);
        }
    else
        alert("Unknown function "+func_name+"!");
    return cmdline;
    }

function start_event()
    {
    active_events+=1;
    //set_fight_message(active_events.toString());
    //show_fight_message(0,0);
    }

function finish_event()
    {
    active_events-=1;
    //set_fight_message(active_events.toString());
    //show_fight_message(0,0);
    if (active_events===0 && completion_resolvers.length>0)
        {
        var resolvers=completion_resolvers;
        completion_resolvers=[];
        resolvers.forEach((resolve) => resolve());
        }
    }

// Generic glue for bridging a Promise-returning function into the
// synchronous-looking playback queue, without touching event_playback()
// or call_function() -- so future async functions can be queued the
// same way, without another rewrite each time. Usage: push
// [0,'promise_event','target_function_name',arg1,arg2,...] followed by
// a [1] barrier entry. Returns 0 (not a delay) so event_playback moves
// on to that [1] immediately; the [1] is what actually awaits it,
// exactly like any other event-gated animation already in this file.
function promise_event(func_name, ...args)
    {
    start_event();
    window[func_name](...args)
        .then(() => finish_event())
        .catch((err) =>
            {
            console.error(`promise_event: ${func_name} rejected:`, err);
            finish_event();
            });
    return 0;
    }
