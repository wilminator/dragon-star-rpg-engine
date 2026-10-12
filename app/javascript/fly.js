var fly=[];
var fly_count=0;
var ifly=false;
var blood_counter=0;
var damage_counter=0;

function make_damage_fly(tobject,qty,color)//(x,y,qty,color)
    {
    var x=object_get_x(tobject);
    var width=get_picture_x(tobject);
    var y=object_get_y(tobject);
    var height=get_picture_y(tobject);
    var timeout,dy;

    var object=object_create('div',fight_screen,{className:'damage'});
    object.appendChild(document.createTextNode(qty));
    object_color(object,color);

    dy=Math.floor(Math.random()*3)-7;
    // How long the number flies: up and back down past the middle of
    // the picture. A short picture (or one not drawn yet, height 0)
    // would put a negative under the root -- NaN, a fly that never
    // ends -- so the root never goes below 0.
    timeout=fly_time(dy,-height/2+50);
    var this_fly=make_fly(x-80+width/2,y+height/2,object,move_blood_fly,timeout,kill_damage_fly);
    object_show(object);
    this_fly.dx=0;
    this_fly.dy=dy;
    }

function make_blood_fly(tobject,qty)//(x,y,qty)
    {
    var index,nx,ny,dx,dy,timeout;
    var value=0;
    var x=object_get_x(tobject);
    var width=get_picture_x(tobject);
    var y=object_get_y(tobject);
    var height=get_picture_y(tobject);

    for(index=0;index<qty;++index)
        {
        var object=object_create('div',fight_screen,{className:'blood'});
        nx=x+Math.floor(Math.random()*width);
        ny=y+Math.floor(Math.random()*height);
        dx=Math.floor(Math.random()*6)-3;
        dy=Math.floor(Math.random()*3)-5;
        //timeout=FLY_TIMEOUT;
        timeout=fly_time(dy,ny-y-height-10);
        //alert(timeout);
        object.appendChild(object_create_image('blood.png',null,{alt:'blood'},effect_images));
        var this_fly=make_fly(nx,ny,object,move_blood_fly,timeout,kill_blood_fly);
        this_fly.dx=dx;
        this_fly.dy=dy;
        object_show(object);
        }
    }

function move_blood_fly(object)
    {
    object.x+=object.dx;
    object.y+=object.dy;
    object.dy+=0.5;
    return false;
    }

function kill_blood_fly(object)
    {
    object_hide(object.object);
    object_delete(object.object);
    }

// The number stays up a second once it lands. Mid-round that holds the
// next fighter back so it can be read; after the round's last hit it
// doesn't hold up command entry (it fades on its own).
function kill_damage_fly(object)
    {
    make_fly(object.x,object.y,object.object,move_damage_stop,40,kill_blood_fly,!fly_round_ending());
    }

// Whether nothing is left to play this round but waits (barriers).
function fly_round_ending()
    {
    if(typeof playback_queue==='undefined' || !playback_queue)
        return false;
    for(var i=0;i<playback_queue.length;i++)
        if(playback_queue[i][0]!==1)
            return false;
    return true;
    }

function move_damage_stop(object)
    {
    return false;
    }

// Ticks for something thrown up at dy (negative is up) to fall $drop
// pixels further than it started: at least 1, and never NaN.
function fly_time(dy,drop)
    {
    var time=Math.ceil((-dy+Math.sqrt(Math.max(0,dy*dy-drop)))*2);
    return time>0?time:1;
    }

// $hold (default true): playback waits for it to finish.
function make_fly(x,y,object,move_fxn,time,kill_fxn,hold)
    {
    hold=(hold!==false);
    if(hold)
        start_event();
    var flyx,flyy,this_fly;
    this_fly={
       x:x,
       y:y,
       move:move_fxn,
       kill:kill_fxn,
       object:object,
       hold:hold,
       time:time};
    fly.push(this_fly);
    object_y(object,y);
    object_x(object,x);
    if(ifly==false)
        ifly=setInterval('move_fly();',25);
    return this_fly;
    }

function move_fly()
    {
    var object,index,this_fly;
    var oldfly=fly;
    fly=[];
    for (index in oldfly)
        {
        this_fly=oldfly[index];
        // Every fly ends by being killed and finishing its event -- one
        // dropped any other way (a time of 0 or NaN) left the round's
        // playback waiting for it forever.
        --this_fly.time;
        if(!(this_fly.time>0) || this_fly.move(this_fly))
            {
            this_fly.kill(this_fly);
            if(this_fly.hold)
                finish_event();
            }
        else
            {
            object_y(this_fly.object,this_fly.y);
            object_x(this_fly.object,this_fly.x);
            fly.push(this_fly);
            }
        }
    }

