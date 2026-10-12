var timerbox_timeout=null;
var timerbox=null;
var timerbox_text=null;
var time_expires=null;

function object_create_timerbox(parent)
    {
    timerbox=object_create('div',parent,{className:'timerbox',align:'center'});
    timerbox_text=document.createTextNode('');
    timerbox.appendChild(timerbox_text);
    object_show(timerbox);
    }

// Audible cues while commands are being entered: every whole minute
// left, and at 30, 15, 10 and 5 seconds. A sound (audio_clips.timer, or
// timer_final for the last 15 seconds) and, for screen readers, the time
// left in words. Each once per countdown; none the moment it starts.
var TIMER_CUES=[30,15,10,5];
var timer_last_cue=null;

function timer_cue_due(seconds)
    {
    if(seconds<=0) return false;
    return seconds%60==0 || TIMER_CUES.indexOf(seconds)!=-1;
    }

function timer_cue(seconds)
    {
    // A countdown starts (the first, or one with more time than the last
    // left): no cue for where it starts. The server's re-syncs of the
    // same countdown don't count as a new one.
    if(timer_last_cue===null || seconds>timer_last_cue)
        {
        timer_last_cue=seconds;
        return null;
        }
    if(seconds==timer_last_cue)
        return null;
    timer_last_cue=seconds;
    if(!timer_cue_due(seconds)) return null;
    // Only while this player is entering commands.
    if(typeof menu_state=='undefined' || typeof teamid=='undefined' || teamid===null
        || menu_state==MENU_STATE_FIGHT_NONE || menu_state==MENU_STATE_FIGHT_WAIT)
        return null;
    var words=seconds>=60
        ? (seconds/60)+(seconds==60?' minute':' minutes')+' left'
        : seconds+' seconds left';
    if(typeof play_sound=='function' && typeof audio_clips!='undefined')
        play_sound(audio_clips[seconds<=15 ? 'timer_final' : 'timer']);
    if(typeof combat_keys_say=='function')
        combat_keys_say(words);
    return words;
    }

// A player controlling monsters has their groups' rows across the top,
// where the timer would cover the last one: it sits beside the Settings
// and Commit buttons instead (timerbox.css, div.timerbox.timerboxlow).
function timerbox_place()
    {
    if(!timerbox || typeof fight=='undefined' || !fight || !fight.parties)
        return;
    var own=fight.parties[player_party];
    timerbox.className=(own && own.monster)?'timerbox timerboxlow':'timerbox';
    }

function update_timerbox(time)
    {
    if(timerbox)
        {
        timerbox_place();
        if(timerbox_timeout)
            clearTimeout(timerbox_timeout);
        timerbox_text.data='';
        if(time)
            {
            var date= new Date();
            time_expires=new Date(time*1000+date.getTime());
            refresh_time_left();
            }
        }
    }

function refresh_time_left()
    {
    var diff=timeout_time_left();
    //Keep counting down, except when a player sitting in the command
    //menu runs out of time: then send what they have. (No extension
    //requests any more: each round's deadline already covers playback,
    //and drops to command-entry time once every player has finished
    //playing back -- see ack_playback.)
    if(diff>0 || menu_state!=MENU_STATE_FIGHT_PLAYER)
        {
        timerbox_timeout=setTimeout('refresh_time_left()',250);
        }
    else
        {
        timerbox_timeout=null;
        commit_commands();
        }

    if (diff<0) diff=0;
    timer_cue(diff);
    var min=Math.floor(diff/60);
    var sec=diff%60;
    sec=(sec<10)?'0'+sec.toString():sec.toString();
    object_color(timerbox,(diff<=10&&(diff%2)==0)?'red':'white');
    timerbox_text.data=min.toString()+':'+sec;
    }

function timeout_time_left()
    {
    var date=new Date();
    return Math.floor((time_expires.getTime()-date.getTime()+999)/1000);
    }
