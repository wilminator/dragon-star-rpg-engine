function slide_right_impact_init()
    {
    this.slide_object=object_create_image(this.images[0],fight_screen,{},effect_images);
    object_x(this.slide_object,-object_get_width(this.slide_object));
    object_y(this.slide_object,player_party==this.tpindex?400:200);
    object_z(this.slide_object,player_party==this.tpindex?150:50);
    object_show(this.slide_object);
    start_event();
    this.setTimeout(1,10);
    play_sound(this.sounds[0]);
    return this.times[0];
    }

function slide_right_impact_move()
    {
    var new_x=object_get_x(this.slide_object)+this.times[1];
    object_x(this.slide_object,new_x);
    if(this.events.length>0)
        {
        var target=this.events[0];
        var data=target[1];
        var pty=data[0];
        var grp=data[1];
        var chr=data[2];
        // A target is hit when the image's middle passes its middle.
        // (This used to measure from half an image width BEHIND the
        // image's left edge, so the hit came late -- too late for the
        // far end of a wide group, which the image had left the screen
        // before reaching.)
        var xpos=object_get_x(this.slide_object)+object_get_width(this.slide_object)/2;
        var pic=get_hero(pty,grp,chr).html.pic;
        var targetpos=object_get_x(pic)+object_get_width(pic)/2;
        if(xpos>targetpos)
            {
            while (true)
                {
                target=this.events.shift();
                default_impact_process_event(target);
                if(this.events.length<1)
                    break;
                target=this.events[0];
                data=target[1];
                var pty2=data[0];
                var grp2=data[1];
                var chr2=data[2];
                if(pty!=pty2 || grp!=grp2 || chr!=chr2)
                    break;
                }
            }
        }
    if(object_get_x(this.slide_object)>800)
        {
        // A safety net: anyone still not hit when the image has left
        // the screen is hit now, so no one is left standing.
        while(this.events.length>0)
            default_impact_process_event(this.events.shift());
        object_delete(this.slide_object);
        this.slide_object=null;
        finish_event();
        }
    else
        this.setTimeout(1,10);
    }

function slide_right_impact()
    {
    this.slide_object=null;
    }

function slide_right_prep()
    {
    slide_right_impact.prototype=new animation();
    slide_right_impact.prototype.functions=[slide_right_impact_init,slide_right_impact_move];
    slide_right_impact.prototype.description=function ()
        {
        return {
            images:['image to scroll across screen'],
            imageloc:[effect_images],
            sounds:['sound image makes as it starts across screen'],
            times:['ms before animation allows next sequence to begin',
                'speed of object in pixels (25)']
            };
        };
    }
slide_right_impact.prototype.prep=slide_right_prep;


if (animations==null)
    {
    var animations={};
    }
try{
    if (animation!=null)
        {
        slide_right_prep();
        }
    }
catch (e) {}
animations['slide_right_impact']=slide_right_impact;
