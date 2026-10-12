"""Draw original back-view pixel-art heroes for Dragon Star (CC0, original work).

Writes <name>.png, <name>_attack.gif and <name>_cast.gif with the same pixel
sizes, frame counts and frame timings as the files they replace.
Usage: python3 heroes.py OUTDIR
"""
import math
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw

PX = 3  # each art pixel becomes a 3x3 block, like the originals
OUTLINE = (24, 18, 30, 255)
SKIN = (236, 188, 150)

# file stem -> (attack size, attack frames, attack ms), (cast size, cast frames, cast ms)
SPECS = {
    "alys": ((122, 128), 2, 80, (183, 192), 8, 80),
    "chaz": ((184, 169), 3, 80, (165, 174), 8, 130),
    "hahn": ((172, 169), 2, 80, (169, 185), 7, 100),
    "nei": ((89, 169), 3, 80, (135, 168), 7, 130),
    "rolf": ((83, 169), 2, 80, (165, 201), 7, 130),
}

# Original designs. Keys are the old file stems so the files keep their names.
HEROES = {
    "alys": dict(name="Mara", hair=(176, 64, 40), hair_style="braid", body=(58, 120, 72), trim=(196, 160, 80),
                 sleeve=(110, 80, 50), pauldron=None, cape=None, weapon="sword", magic="flare", magic_col=(255, 170, 60)),
    "chaz": dict(name="Dax", hair=(40, 34, 40), hair_style="spiky", body=(150, 104, 52), trim=(220, 190, 110),
                 sleeve=(120, 40, 40), pauldron=(184, 136, 70), cape=None, weapon="greatsword", magic="rings", magic_col=(120, 220, 255)),
    "hahn": dict(name="Tobin", hair=(150, 150, 160), hair_style="short", body=(46, 70, 140), trim=(230, 210, 120),
                 sleeve=(46, 70, 140), pauldron=None, cape=(36, 52, 104), weapon="staff", magic="sparkle", magic_col=(140, 255, 160)),
    "nei": dict(name="Kira", hair=(250, 140, 180), hair_style="bob", body=(232, 120, 40), trim=(60, 50, 60),
                sleeve=SKIN, pauldron=None, cape=None, weapon="claws", magic="rings", magic_col=(255, 120, 220)),
    "rolf": dict(name="Ryn", hair=(236, 236, 230), hair_style="tail", body=(96, 56, 130), trim=(200, 200, 210),
                 sleeve=(70, 40, 96), pauldron=(150, 150, 160), cape=(70, 40, 96), weapon="dagger", magic="bolt", magic_col=(255, 240, 120)),
}


def shift(m, dx, dy):
    """Shift a boolean mask without wrapping; vacated cells are False."""
    out = np.zeros_like(m)
    h, w = m.shape
    out[max(0, dy):h + min(0, dy), max(0, dx):w + min(0, dx)] = m[max(0, -dy):h - max(0, dy), max(0, -dx):w - max(0, dx)]
    return out


def shade(c, k):
    return tuple(max(0, min(255, int(v * k))) for v in c[:3]) + (255,)


class Art:
    """A low-resolution RGBA canvas with shaded, outlined shape layers."""

    def __init__(self, w, h):
        self.w, self.h = w, h
        self.img = Image.new("RGBA", (w, h), (0, 0, 0, 0))

    def layer(self, color, draw_fn, light=True):
        mask = Image.new("L", (self.w, self.h), 0)
        draw_fn(ImageDraw.Draw(mask))
        m = np.array(mask) > 0
        if not m.any():
            return
        base = np.zeros((self.h, self.w, 4), np.uint8)
        base[m] = shade(color, 1.0)
        if light:
            # light from the upper right: edges facing it get a highlight, the others a shadow
            sh = m & ~shift(m, -2, 2)
            hi = m & ~shift(m, 1, -1)
            base[sh] = shade(color, 0.68)
            base[hi & ~sh] = shade(color, 1.22)
        layer = Image.fromarray(base, "RGBA")
        self.img.alpha_composite(layer)

    def outline(self):
        a = np.array(self.img)
        m = a[..., 3] > 0
        p = np.pad(m, 1)
        edge = (p[:-2, 1:-1] | p[2:, 1:-1] | p[1:-1, :-2] | p[1:-1, 2:]) & ~m
        a[edge] = OUTLINE
        self.img = Image.fromarray(a, "RGBA")

    def paste(self, other, x, y):
        self.img.alpha_composite(other.img, (int(x), int(y)))


def body(h, arm="down", weapon_pose=None, cast_glow=0.0):
    """Draw the hero from behind in a 48x46 art box; returns Art and the raised-hand position."""
    a = Art(48, 46)
    ox = 4  # left margin so a raised arm or weapon has room
    hand = None
    # cape behind the body
    if h["cape"]:
        a.layer(h["cape"], lambda d: d.polygon([(ox + 6, 22), (ox + 34, 22), (ox + 37, 46), (ox + 3, 46)], fill=255))
    # arms (left always down)
    a.layer(h["sleeve"], lambda d: d.line([(ox + 4, 26), (ox + 2, 42)], fill=255, width=5))
    a.layer(SKIN, lambda d: d.ellipse([ox, 40, ox + 5, 45], fill=255))
    if arm == "down":
        a.layer(h["sleeve"], lambda d: d.line([(ox + 36, 26), (ox + 38, 42)], fill=255, width=5))
        a.layer(SKIN, lambda d: d.ellipse([ox + 35, 40, ox + 40, 45], fill=255))
    # torso
    a.layer(h["body"], lambda d: d.polygon([(ox + 5, 46), (ox + 6, 27), (ox + 10, 22), (ox + 30, 22), (ox + 34, 27), (ox + 35, 46)], fill=255))
    a.layer(h["trim"], lambda d: d.rectangle([ox + 7, 38, ox + 33, 40], fill=255), light=False)
    a.layer(h["trim"], lambda d: d.line([(ox + 20, 23), (ox + 20, 37)], fill=255, width=1), light=False)
    if h["pauldron"]:
        a.layer(h["pauldron"], lambda d: d.ellipse([ox + 3, 21, ox + 13, 29], fill=255))
        a.layer(h["pauldron"], lambda d: d.ellipse([ox + 27, 21, ox + 37, 29], fill=255))
    # neck and head
    a.layer(SKIN, lambda d: d.rectangle([ox + 17, 17, ox + 23, 23], fill=255))
    a.layer(SKIN, lambda d: d.ellipse([ox + 11, 6, ox + 15, 13], fill=255))  # ears
    a.layer(SKIN, lambda d: d.ellipse([ox + 25, 6, ox + 29, 13], fill=255))
    hair = h["hair"]
    style = h["hair_style"]
    a.layer(hair, lambda d: d.ellipse([ox + 12, 1, ox + 28, 18], fill=255))
    if style == "braid":
        a.layer(hair, lambda d: [d.ellipse([ox + 17, 16 + i * 4, ox + 23, 21 + i * 4], fill=255) for i in range(5)])
        a.layer(h["trim"], lambda d: d.rectangle([ox + 18, 36, ox + 22, 37], fill=255), light=False)
    elif style == "spiky":
        a.layer(hair, lambda d: d.polygon([(ox + 11, 8), (ox + 8, 2), (ox + 14, 4), (ox + 16, -1), (ox + 20, 3), (ox + 24, -1),
                                           (ox + 26, 4), (ox + 32, 2), (ox + 29, 9), (ox + 31, 14), (ox + 27, 15), (ox + 13, 15), (ox + 9, 14)], fill=255))
    elif style == "short":
        a.layer(hair, lambda d: d.rectangle([ox + 13, 10, ox + 27, 15], fill=255))
    elif style == "bob":
        a.layer(hair, lambda d: d.rounded_rectangle([ox + 10, 3, ox + 30, 19], radius=6, fill=255))
    elif style == "tail":
        a.layer(hair, lambda d: d.polygon([(ox + 18, 14), (ox + 22, 14), (ox + 24, 28), (ox + 20, 32), (ox + 16, 28)], fill=255))
        a.layer(h["trim"], lambda d: d.rectangle([ox + 18, 15, ox + 22, 16], fill=255), light=False)
    if arm == "up":
        # right arm raised toward the enemy, hand top-right
        a.layer(h["sleeve"], lambda d: d.line([(ox + 34, 26), (ox + 39, 15), (ox + 40, 8)], fill=255, width=5))
        a.layer(SKIN, lambda d: d.ellipse([ox + 37, 3, ox + 43, 9], fill=255))
        hand = (ox + 40, 5)
    a.outline()
    return a, hand


def weapon_art(kind, angle, length_scale=1.0):
    """Weapon drawn pointing along angle (degrees, 0 = right, 90 = up) from its grip at the box centre."""
    size = 64
    w = Art(size, size)
    cx = cy = size // 2
    rad = math.radians(angle)
    ux, uy = math.cos(rad), -math.sin(rad)
    L = {"sword": 22, "greatsword": 29, "staff": 28, "claws": 9, "dagger": 12}[kind] * length_scale
    tip = (cx + ux * L, cy + uy * L)
    back = (cx - ux * 4, cy - uy * 4)
    blade = (205, 215, 230)
    if kind in ("sword", "greatsword", "dagger"):
        wid = {"sword": 3, "greatsword": 5, "dagger": 2}[kind]
        w.layer(blade, lambda d: d.line([(cx, cy), tip], fill=255, width=wid))
        gx, gy = -uy * 4, ux * 4
        w.layer((200, 160, 60), lambda d: d.line([(cx - gx, cy - gy), (cx + gx, cy + gy)], fill=255, width=2), light=False)
        w.layer((90, 60, 40), lambda d: d.line([(cx, cy), back], fill=255, width=2), light=False)
    elif kind == "staff":
        w.layer((120, 80, 40), lambda d: d.line([back, tip], fill=255, width=3))
        w.layer((140, 255, 170), lambda d: d.ellipse([tip[0] - 3, tip[1] - 3, tip[0] + 3, tip[1] + 3], fill=255))
    elif kind == "claws":
        for off in (-3, 0, 3):
            px, py = -uy * off, ux * off
            w.layer(blade, lambda d, px=px, py=py: d.line([(cx + px, cy + py), (tip[0] + px, tip[1] + py)], fill=255, width=1), light=False)
    w.outline()
    return w


def slash_arc(art, cx, cy, r, a0, a1, color=(255, 255, 255)):
    lay = Art(art.w, art.h)
    lay.layer(color, lambda d: d.arc([cx - r, cy - r, cx + r, cy + r], a0, a1, fill=255, width=2), light=False)
    lay.layer((180, 220, 255), lambda d: d.arc([cx - r + 3, cy - r + 3, cx + r - 3, cy + r - 3], a0 + 10, a1 - 10, fill=255, width=1), light=False)
    art.img.alpha_composite(lay.img)


def magic(kind, color, phase, size):
    """Spell effect centred in a size x size box; phase in 0..1 grows then fades."""
    m = Art(size, size)
    c = size / 2
    col = color
    pale = tuple(min(255, int(v * 0.5 + 128)) for v in color)
    if phase <= 0:
        return m
    if kind == "rings":
        r = 3 + phase * (size / 2 - 3)
        m.layer(col, lambda d: d.ellipse([c - r, c - r, c + r, c + r], outline=255, width=2), light=False)
        r2 = r * 0.55
        m.layer(pale, lambda d: d.ellipse([c - r2, c - r2, c + r2, c + r2], fill=255), light=False)
    elif kind == "sparkle":
        n = 6
        for i in range(n):
            ang = 2 * math.pi * i / n + phase * 2.5
            rr = 3 + phase * (size / 2 - 5)
            x, y = c + math.cos(ang) * rr, c + math.sin(ang) * rr
            s = 1 + int(2 * (1 - abs(phase - 0.5) * 2))
            m.layer(col, lambda d, x=x, y=y, s=s: (d.line([(x - s - 1, y), (x + s + 1, y)], fill=255), d.line([(x, y - s - 1), (x, y + s + 1)], fill=255)), light=False)
        m.layer(pale, lambda d: d.ellipse([c - 2, c - 2, c + 2, c + 2], fill=255), light=False)
    elif kind == "flare":
        r = 2 + phase * (size / 2 - 2)
        pts = []
        for i in range(16):
            ang = math.pi * i / 8
            rr = r if i % 2 == 0 else r * 0.42
            pts.append((c + math.cos(ang) * rr, c + math.sin(ang) * rr))
        m.layer(col, lambda d: d.polygon(pts, fill=255), light=False)
        m.layer(pale, lambda d: d.ellipse([c - r * 0.3, c - r * 0.3, c + r * 0.3, c + r * 0.3], fill=255), light=False)
    elif kind == "bolt":
        rng = np.random.default_rng(int(phase * 100))
        for k in range(3):
            ang = rng.uniform(0, 2 * math.pi)
            pts = [(c, c)]
            for s in range(1, 5):
                rr = s / 4 * phase * (size / 2 - 1)
                pts.append((c + math.cos(ang) * rr + rng.uniform(-2, 2), c + math.sin(ang) * rr + rng.uniform(-2, 2)))
            m.layer(col, lambda d, pts=pts: d.line(pts, fill=255, width=1), light=False)
        m.layer(pale, lambda d: d.ellipse([c - 2, c - 2, c + 2, c + 2], fill=255), light=False)
    return m


def to_size(art, w, h, px=PX):
    """Upscale by px and fit to exactly w x h, keeping the bottom-left anchored."""
    big = art.img.resize((art.w * px, art.h * px), Image.NEAREST)
    out = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    out.alpha_composite(big, (0, h - big.height)) if big.height <= h else out.alpha_composite(big.crop((0, big.height - h, big.width, big.height)), (0, 0))
    if big.width > w:
        out = out.crop((0, 0, w, h))
    return out


def save_gif(frames, path, ms):
    """Save RGBA frames as a looping GIF with an exact shared palette; index 0 is transparent."""
    colors = {}
    idx = []
    for f in frames:
        a = np.array(f)
        opaque = a[..., 3] >= 128
        rgb = a[..., :3].reshape(-1, 3)
        keys = (rgb[:, 0].astype(np.int32) << 16) | (rgb[:, 1].astype(np.int32) << 8) | rgb[:, 2]
        keys = np.where(opaque.reshape(-1), keys, -1)
        for k in np.unique(keys):
            if k >= 0 and k not in colors:
                colors[k] = len(colors) + 1
        lut = {k: v for k, v in colors.items()}
        idx.append(np.array([lut.get(k, 0) for k in keys], np.uint8).reshape(a.shape[:2]))
    assert len(colors) <= 255, f"{path}: {len(colors)} colours"
    palette = [255, 0, 255]
    for k in colors:
        palette += [(k >> 16) & 255, (k >> 8) & 255, k & 255]
    palette += [0] * (768 - len(palette))
    out = []
    for i in idx:
        im = Image.fromarray(i, "P")
        im.putpalette(palette)
        out.append(im)
    out[0].save(path, save_all=True, append_images=out[1:], duration=ms, loop=0, disposal=2,
                transparency=0, optimize=False)


def centred(width, art_w):
    return max(0, (width - art_w) // 2)


def make_hero(stem, h, outdir):
    (aw, ah), an, ams, (cw, ch), cn, cms = SPECS[stem]
    # base: 120x120 portrait (40x40 art)
    b, _ = body(h)
    base = Art(40, 40)
    base.paste(b, -4, -6)
    to_size(base, 120, 120).save(outdir / f"{stem}.png")

    # attack: body plus a weapon swing, bottom anchored
    apx = 2 if aw < 100 else PX  # narrow attack canvases use smaller art pixels so the hero still fits
    lw, lh = math.ceil(aw / apx), math.ceil(ah / apx)
    poses = {2: [(120, None), (-10, (200, 340))], 3: [(120, None), (20, (160, 300)), (-50, (260, 360))]}[an]
    frames = []
    scale = min(1.0, (lw - 2) / 48)
    for ang, arc in poses:
        a = Art(lw, lh)
        bb, _ = body(h, arm="up")
        if scale < 1:
            bb.img = bb.img.resize((int(48 * scale), int(46 * scale)), Image.NEAREST)
            bb.w, bb.h = bb.img.size
        bx = centred(lw, bb.w) if lw < 60 else max(0, lw // 2 - 26)
        by = lh - bb.h
        hx, hy = bx + 44 * scale, by + 6 * scale
        if arc:
            slash_arc(a, hx, hy + 6, int(min(lw, lh) * 0.32), *arc)
        a.paste(bb, bx, by)
        wpn = weapon_art(h["weapon"], ang, length_scale=max(0.6, scale) * (1.3 if lw > 50 else 1.0))
        a.paste(wpn, hx - 32, hy - 32)
        frames.append(to_size(a, aw, ah, apx))
    save_gif(frames, outdir / f"{stem}_attack.gif", ams)

    # cast: arm up, spell builds at the hand then fades; body anchored bottom-left like the originals
    lw, lh = math.ceil(cw / PX), math.ceil(ch / PX)
    frames = []
    for i in range(cn):
        a = Art(lw, lh)
        last = i >= cn - 2
        bb, hand = body(h, arm="down" if last and i == cn - 1 else "up")
        by = lh - bb.h
        a.paste(bb, 0, by)
        if hand and not last:
            phase = (i + 1) / (cn - 2)
            size = int(min(lw - 20, lh - 30, 40))
            m = magic(h["magic"], h["magic_col"], phase, size)
            mx, my = hand[0] - size / 2, by + hand[1] - size / 2
            mx = min(mx, lw - size)
            my = max(0, my)
            a.paste(m, mx, my)
        frames.append(to_size(a, cw, ch))
    save_gif(frames, outdir / f"{stem}_cast.gif", cms)


if __name__ == "__main__":
    out = Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    for stem, h in HEROES.items():
        make_hero(stem, h, out)
        print(stem, "->", h["name"])
