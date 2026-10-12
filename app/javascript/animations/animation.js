// Attack and spell animations: the ones a player can turn off (settings.js,
// hide_actions). The rest are the fighters' own poses -- standing, being
// hit, dying, fleeing -- which show who is alive.
var ACTION_ANIMATIONS=['attack_close_animation','electric','fireball','firenuke','multi_impact','ranged_arc_impact',
    'slide_right_impact','static_impact','static_individual_impact','static_multi_impact'];

function invoke_animation(name,subject,target,range,events,data)
    {
    if(!(name in animations))
        return 0;
    if(ACTION_ANIMATIONS.indexOf(name)!=-1 && typeof settings_hidden==='function' && settings_hidden('hide_actions'))
        {
        // Hidden: what it does still happens, at once -- the damage,
        // healing and deaths it carries.
        if(events && events.length)
            default_group_impact_processor(target.party,target.group,target.character,range,events);
        return 0;
        }
    var animation_constructor=animations[name];
    //var animation_instance=new animation_constructor();
    var animation_instance=new (animations[name])();
    return animation_instance.start(subject,target,range,events,data);
    }

function animation_start(subject,target,range,events,data)
    {
    this.subject=subject;
    this.pindex=subject.party;
    this.gindex=subject.group;
    this.cindex=subject.character;
    this.target=target;
    this.tpindex=target.party;
    this.tgindex=target.group;
    this.tcindex=target.character;
    this.data=data;
    this.range=range;
    this.events=events;
    this.images=data.images;
    this.sounds=data.sounds;
    this.times=data.times;
    return this.invoke(0);
    }

function animation_timeout(functionIndex,delay)
    {
    var that=this;
    timeoutFunction=function(){that.invoke(functionIndex);};
    setTimeout(timeoutFunction,delay);
    }

function animation_invoke(functionIndex)
    {
    this.invokeFunction=this.functions[functionIndex];
    return this.invokeFunction();
    }

//Abstract class animation.
function animation() {this.toString=function(){return "animation";};}

animation.prototype.start=animation_start;
animation.prototype.setTimeout=animation_timeout;
animation.prototype.invoke=animation_invoke;
//Functions must be defined for use with start and setTimeout.
animation.prototype.functions=[];
/*
Description must be set for admin interface to know what is needed.
slide_right_impact.prototype.description=function ()
    {
    return {
        images:['description of array of available images'],
        sounds:['description of array of available sounds'],
        times:['description of array of timing values']
        };
    }
*/

if (animations==null)
    var animations={};

for (var index in animations)
    animations[index].prototype.prep();
