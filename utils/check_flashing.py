#!/usr/bin/env python3
"""Checks animated GIFs for flashing (WCAG 2.3.1, Three Flashes or Below
Threshold), as they'd show on the fight screen's black stage.

    python3 utils/check_flashing.py app/images/effects/*.gif app/images/fighters/*.gif

Needs Pillow (pip install pillow). For each GIF it reports the most
flashes in any one second at any one spot, and how much of the image
flashes more than 3 times a second. A flash is a pair of opposing changes
in brightness at the same spot, each of at least 10% of relative
luminance with the darker state below 0.8 (WCAG's general flash), so
something merely moving across the screen doesn't count.

  FLASHES     the spots flashing more than 3 times a second add up to
              more than WCAG's small safe area (341 x 256 px). Slow the
              frames down: 3 flashes a second needs at least 167 ms
              between frames that swing.
  borderline  over a quarter of that area: fine at normal size, but page
              zoom scales it up. Worth slowing down too.
  ok          otherwise.

Only the GIF itself is judged: how the game moves or scales it isn't.
"""
import sys
from PIL import Image, ImageSequence

LUT = []
for i in range(256):
    v = i / 255
    LUT.append(v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4)


def luminances(frame):
    stage = Image.new('RGBA', frame.size, (0, 0, 0, 255))  # the fight's black stage
    rgb = Image.alpha_composite(stage, frame.convert('RGBA')).convert('RGB')
    return [0.2126 * LUT[r] + 0.7152 * LUT[g] + 0.0722 * LUT[b] for r, g, b in rgb.getdata()]


# WCAG's "small safe area": a quarter of 10 degrees of vision, about 341 x
# 256 px on a 1024 x 768 screen at a typical distance. Flashing smaller
# than this, combined, passes; zooming the page in scales it up.
SAFE_AREA_PX = 341 * 256


def check(path):
    image = Image.open(path)
    frames, times = [], []
    for frame in ImageSequence.Iterator(image):
        frames.append(luminances(frame))
        times.append((frame.info.get('duration') or 100) / 1000)
    loops = 'loop' in image.info
    # Play it for two seconds (or once, if it doesn't loop): when each frame shows.
    shown, clock, i = [], 0.0, 0
    while clock < 2.0 or not shown:
        shown.append((clock, i))
        clock += times[i]
        i += 1
        if i == len(frames):
            if not loops:
                break
            i = 0
    # For each spot: the times it swings the other way (a flash is two).
    area = len(frames[0])
    too_many = 0
    worst = 0.0
    for px in range(area):
        swings, last_dir, prev = [], 0, frames[shown[0][1]][px]
        for t, f in shown[1:]:
            now = frames[f][px]
            if abs(now - prev) >= 0.1 and min(now, prev) < 0.8:
                d = 1 if now > prev else -1
                if d != last_dir:
                    swings.append(t)
                    last_dir = d
                prev = now
        best = 0
        for k, t in enumerate(swings):
            best = max(best, sum(1 for u in swings[k:] if u < t + 1))
        flashes = best / 2
        worst = max(worst, flashes)
        if flashes > 3:
            too_many += 1
    verdict = 'FLASHES' if too_many > SAFE_AREA_PX else ('borderline' if too_many > SAFE_AREA_PX / 4 else 'ok')
    print(f"{verdict:10s} {path}: up to {worst:.1f} flashes/s in one spot; {too_many} px flash more than 3 times"
          f" a second ({too_many / SAFE_AREA_PX:.0%} of the safe-area limit){', loops' if loops else ''},"
          f" fastest frame {min(times) * 1000:.0f} ms")
    return verdict != 'FLASHES'


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    results = [check(p) for p in sys.argv[1:] if p.lower().endswith('.gif')]
    sys.exit(0 if all(results) else 1)
