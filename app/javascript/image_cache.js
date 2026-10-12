var image_list={};

// One promise per push_image()/cache_images() call, resolved once
// that specific image finishes loading (or fails to -- resolved
// either way, since one missing/broken image shouldn't block the
// rest of a fight's resources from being considered ready). Separate
// from image_list itself, which stays the real Image objects it
// always was: get_image() and other existing code read image_list[name]
// directly expecting an Image, not a Promise.
var image_load_promises={};

function push_image_array(list)
    {
    return list.map(push_image);
    }

function push_image(image_name)
    {
    if(!image_list[image_name])
        {
        var image=new Image();
        image_list[image_name]=image;
        image_load_promises[image_name]=new Promise(function(resolve)
            {
            image.onload=function(){resolve({success:true,url:image_name,kind:'image'});};
            image.onerror=function(){resolve({success:false,url:image_name,kind:'image'});};
            });
        image.src=image_name;
        }
    return image_load_promises[image_name];
    }

function get_image(name)
    {
    if(!(name in image_list))
        push_image(name);
    return image_list[name];
    }

function cache_images()
    {
    var index, image;
    var promises=[];
    for(index in image_list)
        if(typeof(image_list[index])=="string" && image_list[index]!=images)
            {
            var url=image_list[index];
            image=new Image();
            image_list[index]=image;
            promises.push(new Promise(function(resolve)
                {
                image.onload=function(){resolve({success:true,url:url,kind:'image'});};
                image.onerror=function(){resolve({success:false,url:url,kind:'image'});};
                }));
            image.src=url;
            }
    return promises;
    }

function preload_hero_images()
    {
    var party,group,character,image,hero;
    var promises=[];
    for(party=0;party<get_fight_length();party++)
        for(group=0;group<get_party_length(party);group++)
            for(character=0;character<get_group_length(party,group);character++)
                {
                hero=get_hero(party,group,character);
                for(image in personalities[hero.personalityid])
                    if(image.slice(-5)=='_data')
                        for(var image2 in personalities[hero.personalityid][image].image)
                            if(personalities[hero.personalityid][image].image[image2]!='')
                                promises.push(push_image(fighter_images+personalities[hero.personalityid][image].image[image2]));
                }

    // Ability/item images come from the resource manifest
    // (known_ability_ids/known_item_ids), not hero.abilities/
    // .inventory directly -- see cache_fight_sounds()'s own comment,
    // and FIGHT::collect_resource_ids()'s docblock, for why.
    for(image in known_ability_ids)
        {
        var ability=abilities[known_ability_ids[image]];
        promises.push(push_image(ability_images+ability.menu_pic));
        for(var image2 in ability.impact_data.images)
            if(ability.impact_data.images[image2]!='')
                promises.push(push_image(effect_images+ability.impact_data.images[image2]));
        }
    for(image in known_item_ids)
        {
        var item=items[known_item_ids[image]];
        promises.push(push_image(item_images+item.menu_pic));
        for(var image2 in item.fight_impact_data.images)
            if(item.fight_impact_data.images[image2]!='')
                promises.push(push_image(effect_images+item.fight_impact_data.images[image2]));
        for(var image2 in item.use_impact_data.images)
            if(item.use_impact_data.images[image2]!='')
                promises.push(push_image(effect_images+item.use_impact_data.images[image2]));
        }
    return promises;
    }
