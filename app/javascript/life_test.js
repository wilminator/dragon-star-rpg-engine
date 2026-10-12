function group_living_count(party,group)
    {
    var character,sum=0;
    for(character=0;character<get_group_length(party,group);++character)
        if(get_hero(party,group,character).current.HP>0)
            sum++;
    return sum;
    }
