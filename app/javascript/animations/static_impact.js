var default_static_object=null;
var default_static_events=null;

function static_impact_init()
    {
    // this.images can legitimately be empty -- a sound-only impact
    // effect, like "Endless Wail" (sounds present, no images).
    // Without this guard, this.images[0] is undefined, and
    // object_set_image()'s dir+src string-coerces that to the
    // literal text "undefined", producing a request for a URL
    // ending in ".../undefined". Matches the same if(this.images &&
    // this.images[0]) guard already used in ranged_arc_impact.js,
    // multi_impact.js, and static_multi_impact.js for this exact
    // situation.
    if(this.images && this.images[0])
        {
        this.static_object=object_create_image(this.images[0],fight_screen,{},effect_images);
        object_x(this.static_object,0);
        object_y(this.static_object,player_party==this.tpindex?400:200);
        object_z(this.static_object,player_party==this.tpindex?150:50);
        object_show(this.static_object);
        }
    start_event();
    play_sound(this.sounds[0]);
    this.setTimeout(1,this.times[0]);
    return this.times[1];
    }

function static_damage(tpindex,tgindex,tcindex,range)
    {
    default_impact_processor(this.tpindex,this.tgindex,this.tcindex,this.range,this.events)
    this.setTimeout(2,this.times[1]-this.times[0]);
    }

function static_cleanup()
    {
    // this.static_object stays null when static_impact_init() skipped
    // creating it (no image to show) -- object_delete() would itself
    // throw on a null argument (it dereferences .parentNode
    // unconditionally), so this needs the same guard.
    if(this.static_object)
        object_delete(this.static_object);
    this.static_object=null;
    finish_event();
    }

function static_impact()
    {
    this.static_object=null;
    }

function static_prep()
    {
    static_impact.prototype=new animation();
    static_impact.prototype.functions=[static_impact_init,static_damage,static_cleanup];
    static_impact.prototype.description=function ()
        {
        return {
            images:['static image to display on screen'],
            imageloc:[effect_images],
            sounds:['sound image makes as it appears on screen'],
            times:['ms before damage is displayed',
                'ms before animation allows next sequence to begin']
            };
        };
    }
static_impact.prototype.prep=static_prep;

if (animations==null)
    {
    var animations={};
    }
try{
    if (animation!=null)
        {
        static_prep();
        }
    }
catch (e) {}
animations['static_impact']=static_impact;
