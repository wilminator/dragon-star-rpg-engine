#!/bin/env bash
pushd .. 
rm dragonstar.zip 
# Core files
zip -r dragonstar.zip dragonstar   -x "dragonstar/.git/*" -x "dragonstar/.git" -x "*/cache/*" -x "dragonstar/app/include/cache copy/*" -x "dragonstar/app/music/*" -x "dragonstar/app/sound/*" -x "dragonstar/app/images/*" -x "dragonstar/backup*.sql" -x "dragonstar/dse_dump" -x "*/errorlog.txt" -x "dragonstar/vendor/*" -x "dragonstar/secrets/*" 
# Select graphic assets
zip -r dragonstar.zip dragonstar/app/images/icons dragonstar/app/images/fonts dragonstar/app/images/effects dragonstar/app/images/fighters/{bandit,crystal,jordie,HUGE_,orange_snuggles,puffin,qbird,red_,sky_,snuggles,yellow_,gray_,blue_}*
popd