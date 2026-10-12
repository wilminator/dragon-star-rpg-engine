function base_animation_init()
    {
    var hero=get_hero(this.pindex,this.gindex,this.cindex);
    var object=hero.html;
    object_set_image(object.img,this.images[0],fighter_images);
    object_x(object.pic,hero.x_offset);
    object_y_base(object.pic,hero.y_offset);
    if(hero.current.HP==0)
        hide_cause_is_dead(this.pindex,this.gindex,this.cindex);
    play_sound(this.sounds[0]);
    if (this.events.includes('revive')){
        start_event();
        this.setTimeout(1, this.times[0]);
        return this.times[0] + 1000/(this.times[1] ?? 1);
    }
    return this.times[0];
    }

function revive_fade()
    {
    const fade_time = this.times[1] ?? 1;
    this.fade+=fade_time;
    if(this.fade>100) this.fade=100;
    var hero=get_hero(this.pindex,this.gindex,this.cindex);
    var object=hero.html.img;
    object_opacity(object,this.fade);
    if(this.fade==100)
        {
        finish_event();
        }
    else
        this.setTimeout(1,10);
    }

function base_animation()
    {
    this.slide_object=null;
    this.fade=0;
    }

function base_animation_prep()
    {
    base_animation.prototype=new animation();
    base_animation.prototype.functions=[base_animation_init, revive_fade];
    base_animation.prototype.description=function ()
        {
        return {
            images:['base image of main object'],
            imageloc:[fighter_images],
            sounds:['sound played when base image is shown'],
            times:['ms before animation allows next sequence to begin',
                'speed of image fade for revive, 10 ms default'
            ]
            };
        };
    }
base_animation.prototype.prep=base_animation_prep;

if (animations==null)
    {
    var animations={};
    }
try{
    if (animation!=null)
        {
        base_animation_prep();
        }
    }
catch (e) {}
animations['base_animation']=base_animation;
