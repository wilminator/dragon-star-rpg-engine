// var jukebox;
var audio_clips={
    hover:'Cursor1.mp3',
    confirm:'',
    select:'Item1.mp3',
    unselect:'Cursor2.mp3',
    cancel:'Cancel1.mp3',
    miss:'Evasion2.mp3',
    hpup:'Recovery7.mp3',
    mpup:'Recovery3.mp3',
    noeffect:'',
    revive:'Cold10.mp3',
    // The round timer's cues (timerbox.js): each minute and 30 seconds
    // left, then 15, 10 and 5.
    timer:'Item1.mp3',
    timer_final:'Cursor1.mp3'
    };
var audio_cached=0;
var sound_list={};
var audio_handles={}
// One promise per load_sound() call, resolved once that specific
// clip finishes loading (or fails to -- resolved either way, same
// reasoning as image_load_promises in image_cache.js).
var sound_load_promises={};
var music_handle=null;
var sound_volume = 100;
var music_volume = 100;

function init_audio(callback)
    {
        soundManager = new SoundManager();
        soundManager.url = swfdir;
        soundManager.flashVersion = 9; // optional: shiny features (default = 8)
        soundManager.useFlashBlock = false; // optionally, enable when you're ready to dive in
        soundManager.onready(callback);
        soundManager.ontimeout(callback);

        soundManager.useHTML5Audio = true;
        soundManager.reboot(); // start SM2 init.
    //jukebox=document.getElementById('Jukebox');
    }
    
function load_static_sounds()
    {
    for (var clip in audio_clips)
        {
        if(audio_clips[clip])
            {
            try {
                load_sound(sound+audio_clips[clip])
                //jukebox.load_sound(sound+audio_clips[clip],9);
                //audio_cached++;
                }
            catch(e){
                alert(e);
                }
            }
        }

    }
function old_create_clip(fullurl)
    {
    index = 0;
    while (soundManager.getSoundByID && soundManager.getSoundByID(fullurl + index))
        index++;
    if (!soundManager.canPlayURL(fullurl))
        {
        if (fullurl.slice(-4)=='.mp3')
            {
            fullurl = fullurl.slice(0,-4) + '.mp3';
            }
        if (!soundManager.canPlayURL(fullurl))
            {
            window.status = fullurl + ' cannot play.  '
            }
        }
    clip = soundManager.createSound({
        id: fullurl+index,
        url: fullurl,
        autoLoad: true, 
        loops: 1
        });
    return clip;
    }

function create_clip(fullurl)
    {
    clip = new Howl({        
        src: [fullurl],
        preload: true, 
        loop: false,
        });
    return clip;
    }

function old_load_sound(fullurl)
    {
    if(!(fullurl in audio_handles))
        {
        clip = create_clip(fullurl);
        audio_handles[fullurl] = [clip];
        }
    }

function load_sound(fullurl)
    {
    if(!(fullurl in audio_handles))
        {
        var clip = create_clip(fullurl);
        audio_handles[fullurl] = clip;
        sound_load_promises[fullurl] = new Promise(function(resolve)
            {
            if(clip.state()=='loaded'){resolve({success:true,url:fullurl,kind:'sound'});return;}
            clip.once('load',function(){resolve({success:true,url:fullurl,kind:'sound'});});
            clip.once('loaderror',function(){resolve({success:false,url:fullurl,kind:'sound'});});
            });
        }
    return sound_load_promises[fullurl];
    }

function add_to_sound_list(url)
    {
    if(url=='') return;
    if(!(url in sound_list))
        sound_list[url]=0;
    sound_list[url]++;
    }

function cache_fight_sounds()
    {
    sound_list={};

    var pindex,gindex,cindex;
    var party,group,character;
    var personality,ability,item;
    var index,index2;

    for(pindex in fight.parties)
        {
        party=fight.parties[pindex];
        for(gindex in party.groups)
            {
            group=party.groups[gindex];
            for(cindex in group.characters)
                {
                character=group.characters[cindex];
                personality=personalities[character.personalityid];
                for(index in personality)
                    {
                    if(index.slice(-5)=='_data')
                        {
                        for(index2 in personality[index].sounds)
                            {
                            add_to_sound_list(personality[index].sounds[index2]);
                            }
                        }
                    }
                }
            }
        }

    // Ability/item sounds come from the resource manifest
    // (known_ability_ids/known_item_ids), not character.abilities/
    // .inventory directly -- see FIGHT::collect_resource_ids()'s own
    // docblock for why: those fields are withheld from any
    // non-own-team character for every viewer, and from EVERY
    // character for a spectator specifically.
    for(index in known_ability_ids)
        {
        ability=abilities[known_ability_ids[index]];
        for(index2 in ability.impact_data.sounds)
            add_to_sound_list(ability.impact_data.sounds[index2]);
        }
    for(index in known_item_ids)
        {
        item=items[known_item_ids[index]];
        for(index2 in item.fight_impact_data.sounds)
            add_to_sound_list(item.fight_impact_data.sounds[index2]);
        for(index2 in item.use_impact_data.sounds)
            add_to_sound_list(item.use_impact_data.sounds[index2]);
        }

    //now load the sounds
    var promises=[];
    for(index in sound_list)
        {
        promises.push(load_sound(sound+index));
        audio_cached++;
        }
    return promises;
    }

function set_music_volume(vol)
    {
    //if(jukebox) jukebox.setMusicVolume(vol);
    music_volume = vol;
    }

function set_sound_volume(vol)
    {
    //if(jukebox) jukebox.setSoundVolume(vol);
    sound_volume = vol;
    }

function old_prep_voices(url,number)
    {
    fullurl = sound+url;
    load_sound(fullurl);
    //jukebox.load_sound(sound+url,number);
    if (audio_handles[fullurl].length < number)
        {
        nextclip = create_clip(fullurl);
        audio_handles[fullurl].unshift(nextclip);
        }
    }

function prep_voices(url,number)
    {
        // Howler maintains a pool of audio voices.
    }

function old_get_clip(fullurl)
    {
    if (!audio_handles[fullurl]) return null;
    clip = audio_handles[fullurl].shift()
    if (audio_handles[fullurl].length == 0 || !audio_handles[fullurl][0].ended)
        {
        nextclip = create_clip(fullurl);
        audio_handles[fullurl].unshift(nextclip);
        }
    audio_handles[fullurl].push(clip);
    return clip;
    }

function get_clip(fullurl)
    {
    if (!audio_handles[fullurl]) return null;
    clip = audio_handles[fullurl];        
    return clip;
    }

function stop_music()
    {
    if (music_handle)
        music_handle.pause();
    /*
    if(jukebox)
        try {
            music_handle.stop();
            //jukebox.stop_music();
            }
        catch(e){
            alert(e);
            }
    */
    }


function play_music(url)
    {
    if (url)
        {
        stop_music();
        load_sound(music+url);
        music_handle = get_clip(music+url);
        music_handle.volume(music_volume);
        music_handle.loop(true);
        music_handle.play();
        }
    }

function old_play_music(url)
    {
    if (url)
        {
        stop_music();
        load_sound(music+url);
        music_handle = get_clip(music+url);
        music_handle.setVolume(music_volume);
        music_handle.play({loops:999});
        /*
        if(jukebox)
            try {
                jukebox.play_music(music+url);
                }
            catch(e){
                alert(e);
                }
        */
        }
    }

function play_sound(url)
    {
    if(url)
        {
        // Load on demand, same as play_music(): a clip that was never
        // preloaded (e.g. LevelUp.mp3, requested only by the fight
        // dialog) otherwise leaves get_clip() returning null, and the
        // .volume() call below throws.
        load_sound(sound+url);
        clip = get_clip(sound+url)
        clip.volume(sound_volume);
        clip.play();
        return clip;
        }
    return null;
    }

function old_pan_sound(clip, pan)
    {
    clip.setPan(pan);
    }
    
function pan_sound(clip, pan)
    {
    clip.stereo(pan);
    }