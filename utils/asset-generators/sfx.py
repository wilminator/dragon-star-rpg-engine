"""Procedurally synthesize Dragon Star replacement sound effects (CC0, original work).

Each sound keeps the original file name and roughly the original length.
Usage: python3 sfx.py OUTDIR
"""
import subprocess
import sys
import wave
from pathlib import Path

import numpy as np

SR = 44100
rng = np.random.default_rng(1234)


def t_axis(dur):
    return np.arange(int(SR * dur)) / SR


def env(n, attack=0.005, release=None, curve=3.0):
    """Attack then exponential-ish decay to zero over n samples."""
    t = np.linspace(0, 1, n)
    a = max(1, int(attack * SR))
    e = (1 - t) ** curve
    e[:a] *= np.linspace(0, 1, a)
    return e


def sweep(f0, f1, dur, shape="sine", exp=True):
    n = int(SR * dur)
    if exp:
        f = f0 * (f1 / f0) ** np.linspace(0, 1, n)
    else:
        f = np.linspace(f0, f1, n)
    ph = 2 * np.pi * np.cumsum(f) / SR
    return osc(ph, shape)


def osc(ph, shape):
    if shape == "sine":
        return np.sin(ph)
    if shape == "square":
        return np.sign(np.sin(ph))
    if shape == "saw":
        return 2 * ((ph / (2 * np.pi)) % 1) - 1
    if shape == "tri":
        return 2 * np.abs(2 * ((ph / (2 * np.pi)) % 1) - 1) - 1
    raise ValueError(shape)


def tone(freq, dur, shape="sine"):
    return osc(2 * np.pi * freq * t_axis(dur), shape)


def noise(dur):
    return rng.uniform(-1, 1, int(SR * dur))


def lowpass(x, cutoff):
    """One-pole lowpass; cutoff may be scalar or per-sample array."""
    cutoff = np.broadcast_to(np.asarray(cutoff, float), x.shape)
    a = 1 - np.exp(-2 * np.pi * cutoff / SR)
    y = np.empty_like(x)
    acc = 0.0
    for i in range(len(x)):
        acc += a[i] * (x[i] - acc)
        y[i] = acc
    return y


def highpass(x, cutoff):
    return x - lowpass(x, cutoff)


def bandpass(x, lo, hi):
    return highpass(lowpass(x, hi), lo)


def reverb(x, tail=0.6, decay=0.45):
    """Cheap multi-tap echo reverb; appends a tail."""
    out = np.concatenate([x, np.zeros(int(SR * tail))])
    for d, g in [(0.029, 0.6), (0.043, 0.5), (0.061, 0.4), (0.083, 0.35), (0.11, 0.3), (0.149, 0.22)]:
        k = int(d * SR)
        out[k:] += decay * g * out[: len(out) - k]
    return out


def fit(x, dur):
    """Trim or pad to an exact length, fading the last 20 ms."""
    n = int(SR * dur)
    x = x[:n] if len(x) >= n else np.concatenate([x, np.zeros(n - len(x))])
    f = min(n, int(0.02 * SR))
    x[-f:] *= np.linspace(1, 0, f)
    return x


def mix(*parts):
    n = max(len(p) for p in parts)
    out = np.zeros(n)
    for p in parts:
        out[: len(p)] += p
    return out


def at(x, delay):
    return np.concatenate([np.zeros(int(SR * delay)), x])


def norm(x, peak=0.89):
    m = np.max(np.abs(x)) or 1
    return x / m * peak


# ---------- building blocks ----------

def thud(dur, f0=140, f1=45, nlp=900, body=1.0, click=0.6):
    n = int(SR * dur)
    b = sweep(f0, f1, dur) * env(n, 0.002, curve=4)
    c = lowpass(noise(dur), nlp) * env(n, 0.001, curve=10)
    return body * b + click * c * 3


def whoosh(dur, lo=300, hi=3000, rise=0.4):
    n = int(SR * dur)
    t = np.linspace(0, 1, n)
    shape = np.where(t < rise, t / rise, (1 - t) / (1 - rise)) ** 1.5
    cut = lo + (hi - lo) * shape
    return lowpass(noise(dur), cut) * shape * 2.5


def chime(freq, dur, bright=1.0):
    n = int(SR * dur)
    x = (tone(freq, dur) + 0.4 * bright * tone(freq * 2.01, dur) + 0.2 * bright * tone(freq * 3.02, dur))
    return x * env(n, 0.003, curve=2.5)


def arp(freqs, step, note_dur, shape="sine", bright=1.0):
    parts = [at(chime(f, note_dur, bright) if shape == "sine" else tone(f, note_dur, shape) * env(int(SR * note_dur), 0.002, curve=2), i * step) for i, f in enumerate(freqs)]
    return mix(*parts)


def explosion(dur, cutoff=1800, rumble=55):
    n = int(SR * dur)
    e = env(n, 0.004, curve=2.2)
    cut = cutoff * (0.15 + 0.85 * (1 - np.linspace(0, 1, n)) ** 2)
    nz = lowpass(noise(dur), cut) * e * 3
    low = sweep(rumble * 1.8, rumble * 0.6, dur) * e
    crackle = (rng.random(n) < 0.004) * rng.uniform(-1, 1, n) * e
    return nz + 0.8 * low + 0.5 * lowpass(crackle, 3000) * 3


def flame(dur, cutoff=1400, crackle=0.004, swell=0.25):
    n = int(SR * dur)
    t = np.linspace(0, 1, n)
    shape = np.minimum(t / swell, 1) * (1 - t) ** 1.2
    roar = lowpass(noise(dur), cutoff * (0.6 + 0.4 * shape)) * shape * 3
    flick = 0.5 + 0.5 * np.sin(2 * np.pi * 9 * t * dur + rng.uniform(0, 6)) ** 2
    cr = lowpass((rng.random(n) < crackle) * rng.uniform(-1, 1, n), 5000) * shape * 4
    return roar * flick + cr


def zap(dur, f0=1800, f1=200):
    n = int(SR * dur)
    return sweep(f0, f1, dur, "square") * env(n, 0.001, curve=2) * 0.6


def blip(f, dur, shape="square"):
    n = int(SR * dur)
    return tone(f, dur, shape) * env(n, 0.001, curve=1.5) * 0.5


# ---------- the 34 sounds ----------

def build():
    S = {}
    S["Absorption2"] = fit(reverb(mix(
        sweep(300, 1200, 1.0) * env(int(SR * 1.0), 0.3, curve=1.5) * 0.5,
        arp([523, 659, 784, 988, 1175, 1319], 0.12, 0.35, bright=0.6) * 0.5,
    ), 0.4), 1.2)
    S["Blow1"] = fit(thud(0.4, 160, 50, 1200), 0.4)
    S["Blow2"] = fit(thud(0.3, 220, 70, 3500, body=0.6, click=1.0), 0.3)
    S["Blow3"] = fit(mix(thud(0.3, 260, 90, 5000, body=0.5, click=1.1), highpass(noise(0.05), 2000) * 0.5), 0.3)
    t = t_axis(1.9)
    wail_f = 520 + 180 * np.sin(2 * np.pi * 1.3 * t) + 90 * np.sin(2 * np.pi * 7 * t)
    wail = osc(2 * np.pi * np.cumsum(wail_f) / SR, "saw")
    S["Cow"] = fit(reverb(lowpass(wail, 1800) * np.sin(np.pi * t / 1.9) ** 0.7 * 0.8 + 0.15 * lowpass(noise(1.9), 3000) * np.sin(np.pi * t / 1.9), 0.3), 1.9)
    S["Earth3"] = fit(mix(thud(0.5, 110, 40, 600, click=0.9), explosion(0.5, 500, 40) * 0.4), 0.5)
    S["Earth8"] = fit(mix(thud(0.6, 90, 30, 500, click=1.0), explosion(0.6, 700, 35) * 0.7), 0.6)
    S["Explosion3"] = fit(mix(explosion(2.3, 2200, 50), at(explosion(1.6, 1500, 45) * 0.6, 0.25)), 2.3)
    S["Explosion4"] = fit(explosion(1.5, 2600, 60), 1.5)
    S["Flame1"] = fit(flame(0.9, 1600, swell=0.15), 0.9)
    S["Flame2"] = fit(mix(flame(1.4, 1200, 0.006), sweep(200, 600, 1.4, "saw") * env(int(SR * 1.4), 0.2, curve=2) * 0.15), 1.4)
    S["Flame3"] = fit(mix(flame(1.2, 1800, 0.003), whoosh(0.5, 400, 4000) * 0.6), 1.2)
    S["Flame4"] = fit(mix(whoosh(0.4, 300, 3000, 0.7), at(flame(0.9, 1400, 0.008, 0.1), 0.35)), 1.3)
    S["Flame7"] = fit(mix(flame(1.6, 1000, 0.008, 0.1), explosion(1.4, 1200, 45) * 0.6), 1.6)
    S["Flame8"] = fit(mix(flame(1.5, 2000, 0.005, 0.35), sweep(120, 60, 1.5, "saw") * env(int(SR * 1.5), 0.3, curve=1.5) * 0.25), 1.5)
    S["Holy2"] = fit(reverb(arp([523, 659, 784, 1047, 1319], 0.14, 0.9), 0.8, 0.5), 1.9)
    S["Holy7"] = fit(reverb(mix(arp([392, 494, 587, 784, 988, 1175], 0.1, 0.9), sweep(200, 2000, 1.2) * env(int(SR * 1.2), 0.5, curve=1.2) * 0.15), 0.8, 0.5), 1.9)
    S["Holy8"] = fit(reverb(mix(chime(784, 1.2), at(chime(1047, 1.0), 0.15), at(chime(1568, 0.8, 0.5), 0.3)), 0.6, 0.5), 1.4)
    S["Item2"] = fit(reverb(arp([880, 1109, 1319, 1760], 0.08, 0.5, bright=0.5), 0.4), 1.2)
    S["Kill2"] = fit(mix(thud(0.5, 200, 40, 2500, click=1.3), highpass(noise(0.08), 1500) * env(int(SR * 0.08), 0.001) * 0.8), 0.5)
    S["Kill8"] = fit(mix(thud(0.4, 300, 60, 4000, body=0.7, click=1.2), whoosh(0.15, 1000, 6000, 0.3) * 0.5), 0.4)
    t = t_axis(1.5)
    gf = 85 + 25 * np.sin(2 * np.pi * 3 * t) + 10 * rng.standard_normal(len(t)).cumsum() / 300
    growl = osc(2 * np.pi * np.cumsum(gf) / SR, "saw") * (0.7 + 0.3 * np.sin(2 * np.pi * 23 * t))
    S["Monster2"] = fit(lowpass(growl, 900) * np.sin(np.pi * t / 1.5) ** 0.5 * 1.2 + 0.2 * lowpass(noise(1.5), 500) * np.sin(np.pi * t / 1.5), 1.5)
    bubbles = mix(*[at(sweep(f, f * 2.2, 0.06) * env(int(SR * 0.06), 0.002, curve=1.5) * 0.6, d)
                    for d, f in zip(np.sort(rng.uniform(0, 1.5, 22)), rng.uniform(250, 700, 22))])
    S["Poison"] = fit(mix(bubbles, lowpass(noise(1.7), 300) * 0.3 * env(int(SR * 1.7), 0.2, curve=1)), 1.7)
    S["Recovery2"] = fit(reverb(mix(sweep(400, 1600, 0.35) * env(int(SR * 0.35), 0.01, curve=1) * 0.5, at(chime(1568, 0.3, 0.4), 0.25)), 0.25), 0.6)
    S["Shot3"] = fit(mix(*[at(zap(0.11, 2200, 300), i * 0.13) for i in range(6)]), 0.9)
    S["Sword3"] = fit(mix(whoosh(0.35, 800, 7000, 0.6), at(chime(2400, 0.45, 0.8) * 0.25, 0.2), at(thud(0.2, 300, 120, 4000, 0.3, 0.8), 0.22)), 0.8)
    S["Water1"] = fit(mix(lowpass(noise(1.2), 2500) * env(int(SR * 1.2), 0.005, curve=3) * 2,
                          mix(*[at(sweep(f, f * 1.8, 0.05) * env(int(SR * 0.05), 0.001) * 0.4, d)
                                for d, f in zip(np.sort(rng.uniform(0.05, 1.0, 16)), rng.uniform(500, 1400, 16))])), 1.2)
    S["Cancel1"] = fit(mix(blip(660, 0.08), at(blip(440, 0.12), 0.09)), 0.3)
    S["Cold10"] = fit(reverb(mix(arp([2093, 1760, 1568, 1319, 1175, 1047, 880], 0.11, 0.5, bright=1.2) * 0.6,
                                 highpass(noise(1.4), 4000) * env(int(SR * 1.4), 0.3, curve=1.5) * 0.25), 0.6, 0.55), 1.7)
    S["Cursor1"] = fit(blip(1320, 0.05), 0.1)
    S["Cursor2"] = fit(mix(blip(990, 0.05), at(blip(1320, 0.07), 0.06)), 0.2)
    S["Evasion2"] = fit(whoosh(0.45, 500, 5000, 0.35), 0.5)
    S["Item1"] = fit(reverb(arp([1047, 1319, 1568, 2093], 0.07, 0.35, "square") * 0.35, 0.3), 0.8)
    S["Recovery3"] = fit(reverb(arp([523, 659, 784, 1047], 0.09, 0.45, bright=0.6), 0.4), 0.9)
    S["Recovery7"] = fit(reverb(mix(arp([587, 740, 880, 1175, 1480], 0.09, 0.5, bright=0.6),
                                    sweep(300, 1200, 0.8) * env(int(SR * 0.8), 0.2, curve=1.5) * 0.15), 0.4), 1.1)
    # die.mp3: hero falls; descending wobbling tone, then a body thud
    t = t_axis(1.1)
    df = 520 * (110 / 520) ** (t / 1.1) * (1 + 0.04 * np.sin(2 * np.pi * 9 * t))
    fall = lowpass(osc(2 * np.pi * np.cumsum(df) / SR, "square"), 2200) * env(len(t), 0.01, curve=1.2) * 0.45
    S["die"] = fit(reverb(mix(fall, at(thud(0.6, 120, 35, 700, click=0.9), 1.0)), 0.3), 1.7)
    # LevelUp.mp3: bright rising fanfare arpeggio with a held top note
    S["LevelUp"] = fit(reverb(mix(arp([523, 659, 784, 1047], 0.09, 0.3, "square") * 0.3,
                                  at(tone(1319, 0.7, "square") * env(int(SR * 0.7), 0.005, curve=1.2) * 0.28, 0.36),
                                  at(chime(1568, 0.9, 0.6) * 0.5, 0.36)), 0.5, 0.45), 1.6)
    return S


def write_mp3(x, path):
    wav = path.with_suffix(".wav")
    pcm = (np.clip(norm(x), -1, 1) * 32767).astype(np.int16)
    with wave.open(str(wav), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(pcm.tobytes())
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(wav), "-codec:a", "libmp3lame", "-q:a", "4",
                    "-metadata", "comment=CC0 - generated for Dragon Star", str(path)], check=True)
    wav.unlink()


if __name__ == "__main__":
    out = Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    for name, x in build().items():
        write_mp3(x, out / f"{name}.mp3")
        print(name, round(len(x) / SR, 2))
