"""Draw original monsters, effects, icons and the forest background for Dragon Star (CC0, original work).

Every file keeps the name, pixel size, colour mode, frame count and frame timing of the one it replaces.
Usage: python3 images.py OUTDIR
"""
import json
import math
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

from heroes import Art, PX, shade

rng = np.random.default_rng(42)


# ---------------------------------------------------------------- output helpers

def upscale(art, w, h, px=PX, anchor="bottom"):
    """Upscale art by px and fit to exactly w x h (centred horizontally, anchored bottom or centre)."""
    big = art.img.resize((art.w * px, art.h * px), Image.NEAREST)
    out = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    x = (w - big.width) // 2
    y = h - big.height if anchor == "bottom" else (h - big.height) // 2
    out.alpha_composite(big, (x, y)) if x >= 0 and y >= 0 else out.paste(big, (x, y), big)
    return out


def save_gif(frames, path, durations):
    """Save RGBA frames as a looping GIF with one shared palette; index 0 is transparent."""
    if isinstance(durations, int):
        durations = [durations] * len(frames)
    w, h = frames[0].size
    strip = Image.new("RGB", (w, h * len(frames)))
    for i, f in enumerate(frames):
        strip.paste(f.convert("RGB"), (0, i * h))
    pal_img = strip.quantize(colors=255, method=Image.MEDIANCUT, dither=Image.NONE)
    pal = pal_img.getpalette()[: 255 * 3]
    full = [255, 0, 255] + pal + [0] * (768 - 3 - len(pal))
    pimg = Image.new("P", (1, 1))
    pimg.putpalette(full)
    out = []
    for f in frames:
        q = f.convert("RGB").quantize(palette=pal_img, dither=Image.NONE)
        a = np.array(q, np.uint8) + 1
        a[np.array(f)[..., 3] < 128] = 0
        im = Image.fromarray(a, "P")
        im.putpalette(full)
        out.append(im)
    # GIF writers merge identical consecutive frames; nudge one opaque pixel to the nearest other
    # palette colour so the frame count (which the game's timings rely on) stays exact
    pal_rgb = np.array(full, np.int32).reshape(-1, 3)
    for k in range(1, len(out)):
        a, b = np.array(out[k - 1]), np.array(out[k])
        if np.array_equal(a, b):
            ys, xs = np.nonzero(b)
            y, x = ys[len(ys) // 2], xs[len(xs) // 2]
            dist = ((pal_rgb[1:] - pal_rgb[b[y, x]]) ** 2).sum(1)
            dist[b[y, x] - 1] = 1 << 30
            b[y, x] = int(np.argmin(dist)) + 1
            im = Image.fromarray(b, "P")
            im.putpalette(full)
            out[k] = im
    out[0].save(path, save_all=True, append_images=out[1:], duration=durations, loop=0, disposal=2,
                transparency=0, optimize=False)
    got = Image.open(path).n_frames
    assert got == len(frames), f"{path}: {got} frames written, {len(frames)} expected"


SHEETS = []  # (name, frames, durations) for the sprite sheet archive


def keep(name, frames, durations=None):
    SHEETS.append((name, frames, durations))


def write_sheets(outdir):
    """One horizontal strip PNG per animation plus a JSON frame map, for reuse in other games."""
    outdir.mkdir(parents=True, exist_ok=True)
    index = {}
    for name, frames, durations in SHEETS:
        w, h = frames[0].size
        strip = Image.new("RGBA", (w * len(frames), h), (0, 0, 0, 0))
        for i, f in enumerate(frames):
            strip.alpha_composite(f.convert("RGBA"), (i * w, 0))
        strip.save(outdir / f"{name}.png")
        meta = {"image": f"{name}.png", "frame_width": w, "frame_height": h, "frames": len(frames)}
        if durations is not None:
            meta["durations_ms"] = durations if isinstance(durations, list) else [durations] * len(frames)
        index[name] = meta
    (outdir / "sheets.json").write_text(json.dumps(index, indent=2) + "\n")


# ---------------------------------------------------------------- monsters (front view)

def eye(a, x, y, r, iris=(250, 220, 60), pupil=(20, 10, 10)):
    a.layer((250, 250, 240), lambda d: d.ellipse([x - r, y - r, x + r, y + r], fill=255), light=False)
    a.layer(iris, lambda d: d.ellipse([x - r + 1, y - r + 1, x + r - 1, y + r - 1], fill=255), light=False)
    a.layer(pupil, lambda d: d.rectangle([x - max(0, r - 3), y - 1, x + max(0, r - 3), y + 1], fill=255), light=False)


def goblin():
    a = Art(44, 44)
    skin, cloth = (110, 170, 70), (120, 84, 50)
    a.layer(cloth, lambda d: (d.rectangle([14, 34, 19, 43], fill=255), d.rectangle([25, 34, 30, 43], fill=255)))
    a.layer((70, 50, 30), lambda d: (d.rectangle([12, 41, 20, 43], fill=255), d.rectangle([24, 41, 32, 43], fill=255)))
    a.layer(cloth, lambda d: d.polygon([(11, 22), (33, 22), (36, 37), (8, 37)], fill=255))
    a.layer((80, 60, 36), lambda d: d.rectangle([10, 31, 34, 32], fill=255), light=False)
    a.layer(skin, lambda d: (d.line([(11, 24), (5, 33)], fill=255, width=4), d.line([(33, 24), (39, 30)], fill=255, width=4)))
    # dagger in the right hand
    a.layer((200, 205, 215), lambda d: d.polygon([(39, 29), (43, 15), (41, 29)], fill=255))
    a.layer((110, 70, 40), lambda d: d.rectangle([38, 29, 41, 32], fill=255), light=False)
    # head with long ears
    a.layer(skin, lambda d: (d.polygon([(10, 12), (0, 6), (11, 17)], fill=255), d.polygon([(34, 12), (44, 6), (33, 17)], fill=255)))
    a.layer(skin, lambda d: d.ellipse([9, 2, 35, 24], fill=255))
    eye(a, 17, 11, 3, iris=(240, 60, 40))
    eye(a, 27, 11, 3, iris=(240, 60, 40))
    a.layer((60, 20, 20), lambda d: d.chord([14, 13, 30, 22], 0, 180, fill=255), light=False)
    a.layer((245, 240, 220), lambda d: [d.polygon([(x, 17), (x + 2, 17), (x + 1, 19)], fill=255) for x in (16, 20, 24, 27)], light=False)
    a.layer((80, 120, 50), lambda d: d.polygon([(20, 13), (22, 9), (24, 13)], fill=255), light=False)  # nose
    a.outline()
    return a


def gremlin():
    a = Art(48, 43)
    wing, skin = (80, 60, 100), (150, 150, 165)
    a.layer(wing, lambda d: d.polygon([(18, 16), (0, 2), (3, 12), (0, 20), (6, 22), (4, 30), (17, 26)], fill=255))
    a.layer(wing, lambda d: d.polygon([(30, 16), (48, 2), (45, 12), (48, 20), (42, 22), (44, 30), (31, 26)], fill=255))
    a.layer((120, 100, 140), lambda d: (d.line([(18, 16), (3, 12)], fill=255), d.line([(30, 16), (45, 12)], fill=255),
                                         d.line([(17, 20), (6, 22)], fill=255), d.line([(31, 20), (42, 22)], fill=255)), light=False)
    a.layer(skin, lambda d: d.line([(30, 36), (40, 40), (44, 34)], fill=255, width=2))  # tail
    a.layer((200, 60, 60), lambda d: d.polygon([(43, 31), (47, 34), (43, 36)], fill=255), light=False)
    a.layer(skin, lambda d: (d.rectangle([17, 34, 21, 42], fill=255), d.rectangle([27, 34, 31, 42], fill=255)))
    a.layer(skin, lambda d: d.ellipse([14, 18, 34, 38], fill=255))
    a.layer((190, 190, 200), lambda d: d.ellipse([18, 24, 30, 36], fill=255), light=False)
    a.layer(skin, lambda d: (d.line([(15, 24), (9, 32)], fill=255, width=3), d.line([(33, 24), (39, 32)], fill=255, width=3)))
    a.layer((230, 230, 220), lambda d: [d.line([(x, 32), (x + dx, 35)], fill=255) for x, dx in ((9, -2), (9, 0), (39, 0), (39, 2))], light=False)
    a.layer((60, 40, 50), lambda d: (d.polygon([(16, 6), (14, 0), (19, 4)], fill=255), d.polygon([(32, 6), (34, 0), (29, 4)], fill=255)))
    a.layer(skin, lambda d: d.ellipse([14, 3, 34, 20], fill=255))
    eye(a, 20, 11, 3, iris=(255, 40, 40))
    eye(a, 28, 11, 3, iris=(255, 40, 40))
    a.layer((40, 20, 30), lambda d: d.line([(19, 16), (22, 17), (24, 16), (26, 17), (29, 16)], fill=255), light=False)
    a.outline()
    return a


def woodman():
    a = Art(63, 60)
    bark, dark, leaf = (120, 82, 48), (70, 46, 28), (70, 150, 60)
    # roots and trunk
    a.layer(bark, lambda d: d.polygon([(18, 59), (22, 50), (24, 22), (40, 22), (42, 50), (46, 59), (38, 55), (32, 59), (26, 55)], fill=255))
    a.layer(dark, lambda d: [d.line([(x, 26), (x + rng.integers(-1, 2), 52)], fill=255) for x in (27, 31, 35, 38)], light=False)
    # branch arms
    a.layer(bark, lambda d: (d.line([(25, 30), (12, 26), (4, 16)], fill=255, width=4), d.line([(12, 26), (6, 30)], fill=255, width=2),
                             d.line([(39, 30), (52, 28), (60, 18)], fill=255, width=4), d.line([(52, 28), (58, 33)], fill=255, width=2)))
    a.layer(leaf, lambda d: (d.ellipse([0, 10, 8, 18], fill=255), d.ellipse([55, 12, 63, 20], fill=255)))
    # crown
    for cx, cy, r in ((32, 12, 12), (20, 15, 8), (44, 15, 8), (26, 6, 7), (38, 6, 7)):
        a.layer(leaf, lambda d, cx=cx, cy=cy, r=r: d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=255))
    a.layer((240, 90, 120), lambda d: [d.ellipse([x - 1, y - 1, x + 1, y + 1], fill=255) for x, y in ((24, 8), (36, 5), (42, 14), (19, 16), (31, 15))], light=False)
    # face carved into the trunk
    a.layer((30, 20, 12), lambda d: (d.ellipse([26, 28, 30, 33], fill=255), d.ellipse([34, 28, 38, 33], fill=255),
                                     d.ellipse([28, 37, 36, 43], fill=255)), light=False)
    a.layer((255, 220, 80), lambda d: (d.rectangle([27, 30, 28, 31], fill=255), d.rectangle([35, 30, 36, 31], fill=255)), light=False)
    a.layer((90, 140, 60), lambda d: [d.ellipse([x, y, x + 2, y + 1], fill=255) for x, y in ((24, 46), (39, 40), (25, 24), (40, 50))], light=False)
    a.outline()
    return a


def wild_pig():
    a = Art(72, 72)
    hide, dark, snout = (130, 86, 54), (70, 44, 30), (220, 150, 140)
    a.layer(dark, lambda d: [d.rectangle([x, 56, x + 6, 71], fill=255) for x in (14, 25, 41, 52)])
    a.layer((40, 30, 24), lambda d: [d.rectangle([x, 68, x + 6, 71], fill=255) for x in (14, 25, 41, 52)], light=False)
    a.layer(hide, lambda d: d.ellipse([6, 22, 66, 64], fill=255))
    # bristly mane
    a.layer(dark, lambda d: d.polygon([(14, 26)] + [(18 + i * 4, 14 + (i % 2) * 6) for i in range(10)] + [(58, 26)], fill=255))
    a.layer(hide, lambda d: (d.polygon([(18, 22), (12, 8), (26, 16)], fill=255), d.polygon([(54, 22), (60, 8), (46, 16)], fill=255)))
    a.layer(hide, lambda d: d.ellipse([18, 14, 54, 50], fill=255))
    eye(a, 28, 26, 3, iris=(220, 40, 30))
    eye(a, 44, 26, 3, iris=(220, 40, 30))
    a.layer(snout, lambda d: d.ellipse([25, 32, 47, 48], fill=255))
    a.layer((120, 60, 60), lambda d: (d.ellipse([30, 38, 34, 43], fill=255), d.ellipse([38, 38, 42, 43], fill=255)), light=False)
    a.layer((245, 240, 220), lambda d: (d.polygon([(26, 44), (18, 30), (22, 44)], fill=255), d.polygon([(46, 44), (54, 30), (50, 44)], fill=255)))
    a.outline()
    return a


def puffin():
    a = Art(64, 84)
    black, white, orange = (36, 34, 44), (240, 240, 235), (240, 120, 40)
    a.layer(orange, lambda d: (d.polygon([(18, 80), (28, 74), (30, 83)], fill=255), d.polygon([(46, 80), (36, 74), (34, 83)], fill=255)))
    a.layer(black, lambda d: d.ellipse([10, 26, 54, 80], fill=255))
    a.layer(black, lambda d: (d.polygon([(12, 40), (2, 66), (14, 62)], fill=255), d.polygon([(52, 40), (62, 66), (50, 62)], fill=255)))
    a.layer(white, lambda d: d.ellipse([18, 36, 46, 78], fill=255))
    a.layer(black, lambda d: d.ellipse([14, 2, 50, 36], fill=255))
    a.layer(white, lambda d: (d.ellipse([16, 10, 31, 30], fill=255), d.ellipse([33, 10, 48, 30], fill=255)))
    a.layer((30, 30, 30), lambda d: (d.ellipse([20, 14, 26, 20], fill=255), d.ellipse([38, 14, 44, 20], fill=255)), light=False)
    a.layer((220, 60, 40), lambda d: (d.ellipse([21, 15, 25, 19], outline=255), d.ellipse([39, 15, 43, 19], outline=255)), light=False)
    # big striped beak
    a.layer(white, lambda d: d.ellipse([14, 8, 50, 34], fill=255))
    a.layer((30, 30, 30), lambda d: (d.ellipse([20, 14, 26, 20], fill=255), d.ellipse([38, 14, 44, 20], fill=255)), light=False)
    a.layer((220, 60, 40), lambda d: (d.ellipse([21, 15, 25, 19], outline=255), d.ellipse([39, 15, 43, 19], outline=255)), light=False)
    a.layer((230, 70, 30), lambda d: d.polygon([(28, 20), (36, 20), (38, 27), (32, 35), (26, 27)], fill=255))
    a.layer((90, 96, 110), lambda d: d.rectangle([28, 20, 36, 21], fill=255), light=False)
    a.layer((250, 210, 60), lambda d: d.line([(27, 25), (37, 25)], fill=255), light=False)
    a.layer((250, 150, 40), lambda d: (d.rectangle([20, 22, 22, 23], fill=255), d.rectangle([42, 22, 44, 23], fill=255)), light=False)
    a.outline()
    return a


def flame(a, x, y, s):
    a.layer((240, 80, 20), lambda d: d.polygon([(x - s, y), (x - s // 2, y - s * 2), (x, y - s), (x + s // 3, y - s * 3), (x + s, y)], fill=255), light=False)
    a.layer((255, 210, 60), lambda d: d.polygon([(x - s // 2, y), (x, y - s * 1.6), (x + s // 2, y)], fill=255), light=False)


def flame_lizard():
    a = Art(56, 65)
    scale, belly = (210, 70, 40), (250, 200, 90)
    a.layer(scale, lambda d: d.line([(34, 56), (46, 58), (52, 50), (50, 40)], fill=255, width=5))  # tail
    flame(a, 50, 40, 4)
    a.layer(scale, lambda d: (d.rectangle([17, 50, 23, 63], fill=255), d.rectangle([31, 50, 37, 63], fill=255)))
    a.layer(scale, lambda d: (d.polygon([(14, 64), (17, 60), (24, 60), (26, 64)], fill=255), d.polygon([(29, 64), (31, 60), (38, 60), (40, 64)], fill=255)))
    a.layer(scale, lambda d: d.ellipse([12, 22, 42, 56], fill=255))
    a.layer(belly, lambda d: d.ellipse([18, 28, 36, 54], fill=255))
    a.layer((220, 160, 60), lambda d: [d.line([(20, y), (34, y)], fill=255) for y in (34, 39, 44, 49)], light=False)
    a.layer(scale, lambda d: (d.line([(14, 30), (6, 40)], fill=255, width=4), d.line([(40, 30), (48, 26)], fill=255, width=4)))
    flame(a, 49, 25, 3)
    a.layer(scale, lambda d: d.ellipse([13, 6, 41, 28], fill=255))
    a.layer(scale, lambda d: d.ellipse([18, 16, 36, 30], fill=255))
    flame(a, 27, 8, 5)
    eye(a, 20, 15, 3, iris=(255, 230, 40))
    eye(a, 34, 15, 3, iris=(255, 230, 40))
    a.layer((120, 20, 20), lambda d: d.line([(21, 24), (27, 26), (33, 24)], fill=255), light=False)
    a.layer((90, 30, 20), lambda d: (d.point((25, 20), fill=255), d.point((29, 20), fill=255)), light=False)
    a.outline()
    return a


def chameleon():
    a = Art(48, 51)
    skin, light, branch = (130, 70, 170), (200, 150, 230), (110, 80, 50)
    a.layer(branch, lambda d: d.line([(0, 46), (48, 42)], fill=255, width=4))
    a.layer((80, 150, 60), lambda d: (d.ellipse([2, 38, 8, 43], fill=255), d.ellipse([40, 36, 46, 41], fill=255)))
    a.layer(skin, lambda d: d.arc([28, 26, 46, 44], 200, 520, fill=255, width=4))  # curled tail
    a.layer(skin, lambda d: d.arc([33, 31, 41, 39], 0, 300, fill=255, width=3))
    a.layer(skin, lambda d: d.ellipse([6, 20, 36, 44], fill=255))
    a.layer(light, lambda d: [d.ellipse([x, y, x + 3, y + 3], fill=255) for x, y in ((12, 26), (19, 24), (26, 27), (15, 33), (23, 34), (29, 32))], light=False)
    a.layer(skin, lambda d: (d.line([(10, 40), (8, 46)], fill=255, width=3), d.line([(28, 40), (30, 45)], fill=255, width=3)))
    a.layer(skin, lambda d: d.polygon([(4, 22), (8, 4), (22, 2), (26, 18), (16, 28)], fill=255))  # head with casque
    a.layer(skin, lambda d: d.ellipse([2, 10, 14, 22], fill=255))  # turret eye
    a.layer((250, 220, 60), lambda d: d.ellipse([5, 13, 11, 19], fill=255), light=False)
    a.layer((20, 10, 20), lambda d: d.rectangle([7, 15, 8, 16], fill=255), light=False)
    a.layer((240, 110, 150), lambda d: d.line([(4, 24), (0, 30)], fill=255, width=1), light=False)
    a.outline()
    return a


def hippo():
    a = Art(52, 57)
    skin, belly, mouth = (130, 120, 160), (180, 170, 200), (200, 80, 100)
    a.layer(skin, lambda d: [d.rectangle([x, 46, x + 8, 56], fill=255) for x in (8, 36)])
    a.layer(skin, lambda d: d.ellipse([4, 24, 48, 54], fill=255))
    a.layer(belly, lambda d: d.ellipse([14, 34, 38, 52], fill=255))
    a.layer(skin, lambda d: (d.ellipse([10, 2, 18, 9], fill=255), d.ellipse([34, 2, 42, 9], fill=255)))
    a.layer(skin, lambda d: d.ellipse([10, 4, 42, 30], fill=255))
    a.layer(mouth, lambda d: d.ellipse([14, 14, 38, 34], fill=255), light=False)  # wide open mouth
    a.layer((150, 50, 70), lambda d: d.ellipse([18, 22, 34, 33], fill=255), light=False)
    a.layer((250, 245, 230), lambda d: [d.rectangle([x, 14, x + 2, 18], fill=255) for x in (16, 33)] + [d.rectangle([x, 30, x + 2, 33], fill=255) for x in (18, 31)], light=False)
    eye(a, 18, 8, 2, iris=(60, 40, 30))
    eye(a, 34, 8, 2, iris=(60, 40, 30))
    a.outline()
    return a


def troll(club_angle=60, crouch=0, dust=0.0, w=36, h=38):
    """Small club-wielding troll (2x pixels). club_angle: 90 = straight up, 0 = forward, -60 = smashed down."""
    a = Art(w, h)
    skin, cloth = (128, 136, 150), (96, 70, 52)
    ox, oy = (w - 36) // 2 - 3, h - 38 + crouch
    if dust > 0:
        r = 3 + dust * 12
        for k, dx in enumerate((-1, 1)):
            cx = ox + 18 + dx * (6 + dust * 10)
            a.layer((190, 180, 160), lambda d, cx=cx: d.ellipse([cx - r / 2, h - 5 - r / 3, cx + r / 2, h - 1], fill=255), light=False)
    a.layer(cloth, lambda d: (d.rectangle([ox + 11, oy + 28, ox + 15, oy + 37], fill=255), d.rectangle([ox + 21, oy + 28, ox + 25, oy + 37], fill=255)))
    a.layer(skin, lambda d: d.ellipse([ox + 5, oy + 11, ox + 31, oy + 33], fill=255))
    a.layer((150, 158, 170), lambda d: d.ellipse([ox + 12, oy + 17, ox + 24, oy + 27], fill=255), light=False)
    a.layer(cloth, lambda d: d.rectangle([ox + 8, oy + 25, ox + 28, oy + 30], fill=255))
    a.layer(skin, lambda d: d.line([(ox + 9, oy + 16), (ox + 4, oy + 27)], fill=255, width=3))
    # club arm
    sx, sy = ox + 27, oy + 16
    rad = math.radians(club_angle)
    hx, hy = sx + 6 * math.cos(rad) * 0.6 + 3, sy - 6 * math.sin(rad)
    tx, ty = hx + 13 * math.cos(rad), hy - 13 * math.sin(rad)
    a.layer(skin, lambda d: d.line([(sx, sy), (hx, hy)], fill=255, width=4))
    a.layer((110, 76, 44), lambda d: d.line([(hx, hy), (tx, ty)], fill=255, width=3))
    a.layer((96, 64, 36), lambda d: d.ellipse([tx - 4, ty - 4, tx + 4, ty + 4], fill=255))
    a.layer((200, 200, 190), lambda d: [d.point((tx + dx, ty + dy), fill=255) for dx, dy in ((-3, 0), (3, 0), (0, -3), (0, 3))], light=False)
    a.layer(skin, lambda d: d.ellipse([ox + 10, oy + 2, ox + 26, oy + 16], fill=255))
    a.layer(skin, lambda d: (d.polygon([(ox + 10, oy + 7), (ox + 5, oy + 3), (ox + 11, oy + 10)], fill=255), d.polygon([(ox + 26, oy + 7), (ox + 31, oy + 3), (ox + 25, oy + 10)], fill=255)))
    a.layer((250, 200, 40), lambda d: (d.point((ox + 15, oy + 8), fill=255), d.point((ox + 21, oy + 8), fill=255)), light=False)
    a.layer((60, 30, 30), lambda d: d.line([(ox + 15, oy + 12), (ox + 21, oy + 12)], fill=255), light=False)
    a.layer((240, 235, 220), lambda d: (d.point((ox + 15, oy + 11), fill=255), d.point((ox + 21, oy + 11), fill=255)), light=False)
    a.outline()
    return a


def troll_attack(n, w, h, brutal):
    frames = []
    swings = 2 if brutal else 1
    per = (n - 1) / swings
    for i in range(n - 1):
        k = (i % per) / per  # 0..1 within one swing
        if k < 0.4:
            ang, cr = 60 + 30 * (k / 0.4), 0
        elif k < 0.6:
            ang, cr = 90 - 150 * ((k - 0.4) / 0.2), 2
        else:
            ang, cr = -60 + 120 * ((k - 0.6) / 0.4), 1 if k < 0.8 else 0
        dust = max(0.0, 1 - abs(k - 0.65) / 0.2) if brutal else 0.0
        frames.append(upscale(troll(ang, cr, dust, w // 2, h // 2), w, h, px=2))
    frames.append(upscale(troll(60, 0, 0, w // 2, h // 2), w, h, px=2))
    return frames


# ---------------------------------------------------------------- background

def forest(w=800, h=600, px=4):
    aw, ah = w // px, h // px
    img = np.zeros((ah, aw, 3), np.uint8)
    y = np.arange(ah)[:, None]
    # canopy and far forest: dark green with dappled light
    base = np.array([18, 52, 30]) + (np.array([10, 30, 14]) * (1 - y / ah * 1.5)).clip(0)
    img[:] = base.astype(np.uint8)[:, None, :]
    spots = rng.random((ah, aw)) < (0.25 * (1 - y / (ah * 0.55))).clip(0)
    img[spots] = (60, 120, 70)
    spots2 = rng.random((ah, aw)) < (0.08 * (1 - y / (ah * 0.45))).clip(0)
    img[spots2] = (140, 200, 130)
    pil = Image.fromarray(img, "RGB")
    d = ImageDraw.Draw(pil)
    # distant trunks
    for x in rng.integers(0, aw, 9):
        d.rectangle([x, 0, x + rng.integers(4, 8), ah * 0.62], fill=(28, 40, 30))
    # ground
    gy = int(ah * 0.58)
    g = np.array(pil)
    ground = rng.choice(np.array([[34, 110, 40], [44, 132, 46], [28, 92, 34], [56, 150, 56]]), size=(ah - gy, aw), p=[0.35, 0.3, 0.25, 0.1])
    shade_band = (np.sin(np.arange(aw)[None, :] / 9 + np.arange(ah - gy)[:, None] / 4) > 0.6)
    ground[shade_band] = (ground[shade_band] * 0.8).astype(int)
    g[gy:] = ground
    pil = Image.fromarray(g.astype(np.uint8), "RGB")
    d = ImageDraw.Draw(pil)
    # foreground trunks with roots and bark
    for x0, tw in ((8, 20), (62, 16), (95, 14), (122, 18), (160, 15), (186, 13)):
        top, bot = 0, gy + rng.integers(2, 8)
        d.polygon([(x0, top), (x0 + tw, top), (x0 + tw + 2, bot - 6), (x0 + tw + 7, bot), (x0 - 7, bot), (x0 - 2, bot - 6)], fill=(96, 66, 44))
        for k in range(0, tw, 3):
            d.line([(x0 + k, top), (x0 + k + rng.integers(-1, 2), bot - 4)], fill=(70, 48, 32))
        d.line([(x0 + tw - 1, top), (x0 + tw + 1, bot - 6)], fill=(130, 96, 64))
        d.ellipse([x0 + tw // 2 - 2, gy * 0.35, x0 + tw // 2 + 1, gy * 0.35 + 4], fill=(40, 28, 20))
        for _ in range(12):  # ivy
            iy = rng.integers(10, bot - 6)
            ix = x0 + rng.integers(0, tw)
            d.rectangle([ix, iy, ix + 1, iy + 1], fill=(70, 150, 60))
    # roots and fallen leaves on the ground
    for _ in range(14):
        rx, ry = rng.integers(0, aw), rng.integers(gy + 6, ah - 4)
        d.line([(rx, ry), (rx + rng.integers(4, 10), ry + rng.integers(-2, 3))], fill=(92, 64, 40), width=1)
    return pil.resize((w, h), Image.NEAREST)


# ---------------------------------------------------------------- effects

def radial(size, colors, cx=None, cy=None, r=None, alpha_edge=True):
    """Soft radial gradient disc through a list of (stop, rgb)."""
    cx = size[0] / 2 if cx is None else cx
    cy = size[1] / 2 if cy is None else cy
    r = min(size) / 2 if r is None else r
    yy, xx = np.mgrid[0:size[1], 0:size[0]]
    t = np.sqrt((xx - cx) ** 2 + (yy - cy) ** 2) / r
    out = np.zeros((size[1], size[0], 4), np.float32)
    stops = [s for s, _ in colors]
    for ch in range(3):
        out[..., ch] = np.interp(t, stops, [c[ch] for _, c in colors])
    out[..., 3] = np.where(t <= 1, 255, 0)
    return Image.fromarray(out.astype(np.uint8), "RGBA")


FIRE = [(0.0, (255, 255, 210)), (0.35, (255, 220, 60)), (0.7, (250, 120, 20)), (1.0, (190, 40, 10))]


def fireball_png(s):
    im = radial((s, s), FIRE, r=s / 2 - 1)
    # flickering edge
    d = ImageDraw.Draw(im)
    for k in range(10):
        ang = 2 * math.pi * k / 10
        x, y = s / 2 + math.cos(ang) * (s / 2 - 2), s / 2 + math.sin(ang) * (s / 2 - 2)
        d.ellipse([x - s / 12, y - s / 12, x + s / 12, y + s / 12], fill=(240, 90, 20, 255))
    return im


def huge_fireball():
    im = Image.new("RGBA", (400, 200), (0, 0, 0, 0))
    tail = Image.new("RGBA", (400, 200), (0, 0, 0, 0))
    d = ImageDraw.Draw(tail)
    for k in range(40):
        t = k / 40
        hw = 8 + 80 * t
        col = tuple(int(a + (b - a) * t) for a, b in zip((255, 230, 120), (240, 90, 20))) + (255,)
        d.polygon([(10 + 230 * t, 100 - hw * 0.9), (300, 100), (10 + 230 * t, 100 + hw * 0.9)], fill=col)
    im.alpha_composite(tail)
    ball = radial((200, 200), FIRE, r=95)
    im.alpha_composite(ball, (200, 0))
    d = ImageDraw.Draw(im)
    for _ in range(40):
        x, y = rng.integers(20, 260), rng.integers(70, 130)
        d.rectangle([x, y, x + 3, y + 3], fill=(255, 240, 150, 255))
    return im


def burst(size, r_out, r_in, points, rot, fill, outline=(20, 10, 10, 255)):
    im = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    c = size / 2
    pts = []
    for i in range(points * 2):
        ang = rot + math.pi * i / points
        rr = r_out if i % 2 == 0 else r_in
        pts.append((c + math.cos(ang) * rr, c + math.sin(ang) * rr))
    d.polygon(pts, fill=fill, outline=outline, width=3)
    return im


def explode_gif():
    frames = []
    for i, (fill, inner) in enumerate([((255, 240, 80), (240, 60, 30)), ((255, 170, 40), (255, 240, 80)),
                                       ((230, 40, 30), (255, 200, 40)), ((120, 110, 110), (180, 170, 170))]):
        f = burst(80, 36 - (6 if i == 3 else 0), 20, 9, i * 0.3, fill + (255,))
        f.alpha_composite(burst(80, 18, 9, 7, -i * 0.4, inner + (255,), outline=inner + (255,)))
        frames.append(f)
    return frames


def explosion_gif():
    frames = []
    for i in range(12):
        t = (i + 1) / 12
        f = Image.new("RGBA", (128, 128), (0, 0, 0, 0))
        r = 8 + 54 * min(1, t * 1.4)
        heat = max(0.0, 1 - t)
        cols = [(0.0, (255, 255, int(200 * heat))), (0.5, (255, int(120 + 100 * heat), 30)), (1.0, (int(120 + 100 * heat), 30, 20))]
        f.alpha_composite(radial((128, 128), cols, r=r))
        if t > 0.5:  # smoke ring as it burns out
            d = ImageDraw.Draw(f)
            d.ellipse([64 - r, 64 - r, 64 + r, 64 + r], outline=(80, 70, 70, 255), width=int(2 + 10 * (t - 0.5)))
        d = ImageDraw.Draw(f)
        for k in range(8):  # debris
            ang = k * math.pi / 4 + 0.3
            rr = r + 4 + 6 * t
            x, y = 64 + math.cos(ang) * rr, 64 + math.sin(ang) * rr
            if x < 126 and y < 126:
                d.rectangle([x, y, x + 2, y + 2], fill=(255, 200, 80, 255) if t < 0.6 else (90, 80, 80, 255))
        frames.append(f)
    return frames


def heal_gif():
    frames = []
    for i in range(10):
        t = i / 9
        f = Image.new("RGBA", (96, 96), (0, 0, 0, 0))
        d = ImageDraw.Draw(f)
        r = 6 + 34 * t
        d.ellipse([48 - r, 48 - r, 48 + r, 48 + r], outline=(140, 255, 170, 255), width=2)
        for k in range(6):  # rising sparkles
            x = 48 + math.cos(k * 1.05 + t * 3) * (12 + k * 3)
            y = 80 - t * 60 - k * 5
            if 4 < y < 92:
                s = 2 + (k + i) % 3
                d.line([(x - s, y), (x + s, y)], fill=(230, 255, 230, 255), width=1)
                d.line([(x, y - s), (x, y + s)], fill=(230, 255, 230, 255), width=1)
        d.rectangle([45, 40 - 4 * t, 51, 56 - 4 * t], fill=(255, 255, 255, 255))
        d.rectangle([40, 45 - 4 * t, 56, 51 - 4 * t], fill=(255, 255, 255, 255))
        frames.append(f)
    return frames


def punch_gif():
    frames = []
    for i, (ro, ri) in enumerate(((14, 7), (30, 14), (40, 20), (28, 18))):
        f = burst(96, ro, ri, 8, i * 0.2, (255, 255, 255, 255), outline=(255, 210, 60, 255))
        if i == 3:
            f.putalpha(f.split()[3].point(lambda v: v // 2 if v else 0).point(lambda v: 255 if v >= 128 else 0))
        frames.append(f)
    return frames


def slash_gif():
    frames = []
    for i in range(4):
        f = Image.new("RGBA", (100, 20), (0, 0, 0, 0))
        d = ImageDraw.Draw(f)
        x0, x1 = -10 + i * 22, 20 + i * 26
        d.polygon([(x0, 14), (x1, 4), (x1 + 4, 6), (x0 + 8, 16)], fill=(235, 245, 255, 255))
        d.line([(x0 + 4, 14), (x1, 6)], fill=(150, 200, 255, 255), width=1)
        frames.append(f)
    return frames


def waterspout_gif():
    frames = []
    for i in range(8):
        f = Image.new("RGBA", (96, 96), (0, 0, 0, 0))
        d = ImageDraw.Draw(f)
        if i < 4:  # column falls from above
            bottom = 24 + i * 24
            d.rectangle([34, 0, 62, bottom], fill=(60, 110, 230, 255))
            for k in range(0, bottom, 4):
                x = 36 + (k * 7 + i * 5) % 24
                d.line([(x, k), (x, k + 3)], fill=(190, 220, 255, 255))
        else:  # splash at the bottom
            t = (i - 3) / 4
            top = 96 - 70 * (1 - t)
            d.rectangle([36, top, 60, 96], fill=(60, 110, 230, 255))
            for k in range(6):
                sx = 48 + (k - 2.5) * (8 + 14 * t)
                sy = 90 - 20 * math.sin(math.pi * min(1, t + k * 0.05))
                d.ellipse([sx - 3, sy - 3, sx + 3, sy + 3], fill=(150, 200, 255, 255))
        frames.append(f)
    return frames


def lightning_gif():
    frames = []
    for i in range(3):
        f = Image.new("RGBA", (800, 200), (0, 0, 0, 0))
        d = ImageDraw.Draw(f)
        for b in range(2):
            pts, y = [], 100 + (b - 0.5) * 30
            for x in range(0, 801, 25):
                y = min(185, max(15, y + rng.integers(-35, 36)))
                pts.append((x, y))
            d.line(pts, fill=(255, 255, 200, 255), width=4 - b)
            d.line([(x, y + 3) for x, y in pts], fill=(180, 200, 255, 255), width=1)
        frames.append(f)
    return frames


def blood_png():
    im = Image.new("RGBA", (8, 8), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.polygon([(4, 0), (7, 5), (4, 7), (1, 5)], fill=(180, 10, 20, 255))
    d.point((3, 4), fill=(240, 90, 90, 255))
    return im


# ---------------------------------------------------------------- icons (96x34 bevelled tiles)

FACE, LEFT, TOP, RIGHT = (119, 176, 198), (29, 99, 132), (39, 71, 165), (133, 155, 211)


def tile(w, h, b=4):
    """Bevelled blue tile in the style of the game's other icons."""
    im = Image.new("RGB", (w, h), FACE)
    d = ImageDraw.Draw(im)
    d.polygon([(0, 0), (w, 0), (w - b, b), (b, b)], fill=TOP)
    d.polygon([(0, h), (w, h), (w - b, h - b), (b, h - b)], fill=TOP)
    d.polygon([(0, 0), (b, b), (b, h - b), (0, h)], fill=LEFT)
    d.polygon([(w, 0), (w - b, b), (w - b, h - b), (w, h)], fill=RIGHT)
    return im


def cross(d, cx, cy, s, col=(220, 30, 30)):
    d.rectangle([cx - s, cy - s * 3, cx + s, cy + s * 3], fill=(250, 250, 250))
    d.rectangle([cx - s * 3, cy - s, cx + s * 3, cy + s], fill=(250, 250, 250))
    d.rectangle([cx - s + 1, cy - s * 3 + 1, cx + s - 1, cy + s * 3 - 1], fill=col)
    d.rectangle([cx - s * 3 + 1, cy - s + 1, cx + s * 3 - 1, cy + s - 1], fill=col)


def ball(im, cx, cy, r, trail=0):
    if trail:
        d = ImageDraw.Draw(im)
        for k in range(trail):
            t = k / trail
            col = (255, int(240 - 120 * t), int(140 - 120 * t))
            d.line([(cx - trail + k, cy - r * 0.5 * t), (cx - trail + k, cy + r * 0.5 * t)], fill=col)
    b = radial((2 * r + 2, 2 * r + 2), FIRE, r=r)
    im.paste(b, (int(cx - r - 1), int(cy - r - 1)), b)


def leaf(d, cx, cy, col, vein):
    d.polygon([(cx - 14, cy + 6), (cx - 4, cy - 8), (cx + 10, cy - 10), (cx + 14, cy - 2), (cx + 2, cy + 8)], fill=col, outline=(30, 40, 30))
    d.line([(cx - 14, cy + 6), (cx + 12, cy - 8)], fill=vein)


def icons():
    out = {}
    W, H = 96, 34
    blank = tile(W, H)
    out["icons/abilities/bignone.png"] = blank.copy()
    out["icons/items/bignone.png"] = blank.copy()
    out["icons/menu/bignone.png"] = blank.copy()
    out["icons/menu/none.png"] = tile(24, 32)
    im = blank.copy(); ball(im, 62, 17, 6, trail=30); out["icons/abilities/fireball.png"] = im
    im = blank.copy(); ball(im, 40, 21, 5, trail=18); ball(im, 66, 13, 6, trail=22); out["icons/abilities/firebomb.png"] = im
    im = blank.copy(); ball(im, 62, 17, 11, trail=44); out["icons/abilities/firenuke.png"] = im
    im = blank.copy(); cross(ImageDraw.Draw(im), 48, 17, 2); out["icons/abilities/heal.png"] = im
    im = blank.copy(); d = ImageDraw.Draw(im); cross(d, 38, 17, 2); cross(d, 58, 17, 2); out["icons/abilities/healsome.png"] = im
    im = blank.copy(); d = ImageDraw.Draw(im); cross(d, 48, 17, 3, col=(240, 200, 40)); out["icons/abilities/life.png"] = im
    im = blank.copy(); d = ImageDraw.Draw(im)
    for side in (-1, 1):  # wings
        for k in range(4):
            d.line([(48 + side * 8, 14 + k * 2), (48 + side * (22 - k * 3), 8 + k * 4)], fill=(250, 250, 250), width=2)
    cross(d, 48, 17, 2, col=(240, 200, 40)); out["icons/abilities/revive.png"] = im
    im = blank.copy(); d = ImageDraw.Draw(im)
    pts = [(10 + k * 8, 17 + (-7 if k % 2 else 7)) for k in range(10)]
    d.line(pts, fill=(40, 30, 10), width=4); d.line(pts, fill=(255, 240, 60), width=2); out["icons/abilities/shockwave.png"] = im
    im = blank.copy(); d = ImageDraw.Draw(im)
    for r in (4, 9, 14):
        d.arc([30 - r, 17 - r, 30 + r, 17 + r], -50, 50, fill=(60, 40, 90), width=2)
        d.arc([48 - r, 17 - r, 48 + r, 17 + r], -50, 50, fill=(140, 90, 200), width=2)
    d.ellipse([18, 13, 26, 21], fill=(200, 200, 220), outline=(40, 40, 60)); d.ellipse([20, 17, 24, 20], fill=(40, 20, 40))
    out["icons/abilities/wail.png"] = im
    im = blank.copy(); d = ImageDraw.Draw(im)
    d.ellipse([38, 9, 56, 27], fill=(40, 40, 50), outline=(10, 10, 20)); d.ellipse([42, 12, 46, 16], fill=(120, 120, 140))
    d.rectangle([45, 5, 49, 9], fill=(90, 90, 100)); d.line([(49, 5), (54, 1)], fill=(150, 110, 60), width=2)
    d.point((55, 1), fill=(255, 220, 60)); out["icons/items/bigbomb.png"] = im
    im = blank.copy(); leaf(ImageDraw.Draw(im), 48, 17, (40, 90, 210), (150, 190, 255)); out["icons/items/blue_leaf.png"] = im
    im = blank.copy(); leaf(ImageDraw.Draw(im), 48, 17, (70, 170, 60), (180, 230, 150)); out["icons/items/green_leaf.png"] = im
    return out


# ---------------------------------------------------------------- main

def build(out):
    f = out / "fighters"
    e = out / "effects"
    for p in (f, e, out / "background", out / "icons/abilities", out / "icons/items", out / "icons/menu"):
        p.mkdir(parents=True, exist_ok=True)

    monsters = {"goblin.png": (goblin, 132, 132), "gremlin.png": (gremlin, 144, 129), "woodman.png": (woodman, 189, 180),
                "wild_pig.png": (wild_pig, 216, 216), "puffin.png": (puffin, 190, 250), "flamin_lizard.png": (flame_lizard, 168, 195),
                "purple_chameleon.png": (chameleon, 144, 153), "hungry_hippo.png": (hippo, 156, 171)}
    for name, (fn, w, h) in monsters.items():
        im = upscale(fn(), w, h)
        im.save(f / name)
        keep("fighters_" + name[:-4], [im])

    t = upscale(troll(), 72, 77, px=2)
    t.convert("P", palette=Image.ADAPTIVE).save(f / "Troll.png", transparency=0) if False else t.save(f / "Troll.png")
    keep("fighters_Troll", [t])
    for name, n, w, h, brutal in (("Troll-Attack.gif", 27, 72, 77, False), ("Troll-BrutalAttack.gif", 38, 76, 83, True)):
        fr = troll_attack(n, w, h, brutal)
        durs = [10] * (n - 1) + [1000]
        save_gif(fr, f / name, durs)
        keep("fighters_" + name[:-4], fr, durs)

    forest().save(out / "background/forest.png")

    fx = {"explode.gif": (explode_gif(), 110), "explosion.gif": (explosion_gif(), 40), "heal.gif": (heal_gif(), 100),
          "punch.gif": (punch_gif(), 100), "slash.gif": (slash_gif(), 100), "waterspout_down.gif": (waterspout_gif(), 100)}
    for name, (fr, ms) in fx.items():
        save_gif(fr, e / name, ms)
        keep("effects_" + name[:-4], fr, ms)
    ls = lightning_gif()
    save_gif(ls, e / "lightening_storm.gif", 80)
    keep("effects_lightening_storm", ls, 80)
    for name, im in (("fireball.png", fireball_png(64)), ("small_fireball.png", fireball_png(32)),
                     ("huge_fireball.png", huge_fireball()), ("blood.png", blood_png())):
        im.save(e / name)
        keep("effects_" + name[:-4], [im])

    icon_imgs = icons()
    for rel, im in icon_imgs.items():
        im.save(out / rel)
    # one tilesheet of all the icons, in a fixed order
    names = sorted(icon_imgs)
    for n in names:
        keep(n.replace("/", "_")[:-4], [icon_imgs[n].convert("RGBA")])


if __name__ == "__main__":
    out = Path(sys.argv[1])
    build(out)
    write_sheets(out / "tilesheets")
    print("done:", len(SHEETS), "sheets")


def bomb_gif():
    """Thrown bomb: dark sphere with a sparking fuse, 2 frames."""
    frames = []
    for i in range(2):
        f = Image.new("RGBA", (35, 50), (0, 0, 0, 0))
        body = radial((34, 34), [(0.0, (150, 150, 165)), (0.45, (70, 70, 82)), (1.0, (20, 20, 28))], cx=12, cy=12, r=26)
        mask = Image.new("L", (34, 34), 0)
        ImageDraw.Draw(mask).ellipse([0, 0, 33, 33], fill=255)
        f.paste(body, (0, 16), mask)
        d = ImageDraw.Draw(f)
        d.rectangle([13, 11, 20, 17], fill=(90, 90, 100, 255))
        d.line([(17, 11), (20, 6), (24, 4)], fill=(170, 130, 80, 255), width=2)
        for k in range(6):  # spark
            ang = k * math.pi / 3 + i * 0.5
            r = 3 + (k + i) % 2 * 2
            d.line([(25, 4), (25 + math.cos(ang) * r, 4 + math.sin(ang) * r)], fill=(255, 220 - 80 * ((k + i) % 2), 60, 255))
        frames.append(f)
    return frames
