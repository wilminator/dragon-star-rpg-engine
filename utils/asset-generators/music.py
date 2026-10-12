"""Procedurally compose a chiptune battle loop for Dragon Star (CC0, original work).

Usage: python3 music.py OUT.mp3
"""
import subprocess
import sys
import wave
from pathlib import Path

import numpy as np

SR = 44100
BPM = 152
BEAT = 60 / BPM
STEP = BEAT / 4  # sixteenth note

rng = np.random.default_rng(7)


def midi(n):
    return 440 * 2 ** ((n - 69) / 12)


def square(f, n, duty=0.5):
    ph = (np.arange(n) * f / SR) % 1
    return np.where(ph < duty, 1.0, -1.0)


def tri(f, n):
    ph = (np.arange(n) * f / SR) % 1
    return 2 * np.abs(2 * ph - 1) - 1


def adsr(n, a=0.005, d=0.06, s=0.6, r=0.03):
    e = np.full(n, s)
    ai, di, ri = int(a * SR), int(d * SR), int(r * SR)
    ai = min(ai, n)
    e[:ai] = np.linspace(0, 1, ai)
    de = min(n, ai + di)
    e[ai:de] = np.linspace(1, s, de - ai)
    if ri < n:
        e[-ri:] *= np.linspace(1, 0, ri)
    return e


# A natural minor, i - VI - VII - i style progression, then a tension section.
# Each chord lasts one bar (16 steps). Root notes as MIDI numbers.
SECTION_A = [57, 53, 55, 57, 57, 53, 55, 52]          # Am F G Am Am F G E
SECTION_B = [50, 53, 48, 52, 50, 53, 55, 52]          # Dm F C E Dm F G E
CHORD = {57: [0, 3, 7], 53: [0, 4, 7], 55: [0, 4, 7], 52: [0, 4, 7], 50: [0, 3, 7], 48: [0, 4, 7]}

LEAD_A = [
    [69, None, 72, None, 76, None, 74, 72, 74, None, 72, None, 69, None, 67, None],
    [69, None, None, None, 72, None, 69, None, 65, None, 64, None, 65, None, 67, None],
    [67, None, 71, None, 74, None, 72, 71, 72, None, 74, None, 76, None, None, None],
    [76, None, 74, None, 72, None, 71, None, 69, None, None, None, None, None, 64, None],
    [69, None, 72, None, 76, None, 79, None, 77, None, 76, None, 74, None, 72, None],
    [77, None, None, 76, 74, None, 72, None, 74, None, 76, None, 77, None, 79, None],
    [79, None, 77, None, 76, None, 74, None, 76, None, 74, None, 71, None, 67, None],
    [68, None, 71, None, 74, None, 76, None, 80, None, None, None, 76, None, None, None],
]
LEAD_B = [
    [74, None, None, 77, None, None, 81, None, 79, None, 77, None, 76, None, 74, None],
    [72, None, None, 77, None, None, 81, None, 84, None, 81, None, 77, None, 72, None],
    [72, None, 76, None, 79, None, 76, None, 72, None, 76, None, 79, None, 84, None],
    [83, None, None, None, 80, None, None, None, 76, None, None, None, 71, None, None, None],
    [74, 77, 81, None, 74, 77, 81, None, 79, None, 77, None, 76, None, 74, None],
    [77, None, 76, None, 77, None, 81, None, 84, None, None, None, 81, None, None, None],
    [79, None, 83, None, 86, None, 83, None, 79, None, 74, None, 71, None, 67, None],
    [76, None, None, None, 74, None, None, None, 71, None, None, None, 68, None, 64, None],
]


class Track:
    def __init__(self, seconds):
        self.buf = np.zeros(int(SR * seconds) + SR)

    def add(self, start, x):
        i = int(start * SR)
        self.buf[i:i + len(x)] += x


def render_bar(trk, t0, root, lead, variant):
    # Bass: driving eighth notes on triangle, octave jumps
    for i in range(8):
        n = root - 12 + (12 if i % 4 == 3 else 0)
        dur = STEP * 2 * 0.9
        x = tri(midi(n), int(dur * SR)) * adsr(int(dur * SR), d=0.05, s=0.8) * 0.5
        trk.add(t0 + i * STEP * 2, x)
    # Arpeggio: 25% pulse, fast chord cycling
    ch = CHORD[root]
    for i in range(16):
        n = root + 12 + ch[i % 3] + (12 if (i // 3) % 2 and variant else 0)
        dur = STEP * 0.95
        x = square(midi(n), int(dur * SR), 0.25) * adsr(int(dur * SR), d=0.04, s=0.35) * 0.09
        trk.add(t0 + i * STEP, x)
    # Lead: 50% pulse with vibrato on held notes
    i = 0
    while i < 16:
        n = lead[i]
        if n is None:
            i += 1
            continue
        length = 1
        while i + length < 16 and lead[i + length] is None:
            length += 1
        dur = STEP * length * 0.92
        m = int(dur * SR)
        t = np.arange(m) / SR
        vib = 1 + 0.006 * np.sin(2 * np.pi * 6 * t) * np.clip((t - 0.12) / 0.1, 0, 1)
        f = midi(n) * vib
        ph = np.cumsum(f) / SR % 1
        x = np.where(ph < 0.5, 1.0, -1.0) * adsr(m, d=0.08, s=0.7) * 0.16
        trk.add(t0 + i * STEP, x)
        i += length
    # Drums: kick on 1 and 3 (+ syncopation), snare on 2 and 4, hats on eighths
    for s in [0, 6, 8, 11] if variant else [0, 8, 10]:
        m = int(0.12 * SR)
        f = 150 * (40 / 150) ** np.linspace(0, 1, m)
        x = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.linspace(1, 0, m) ** 2 * 0.7
        trk.add(t0 + s * STEP, x)
    for s in [4, 12] + ([15] if variant else []):
        m = int(0.14 * SR)
        x = rng.uniform(-1, 1, m) * np.linspace(1, 0, m) ** 3 * 0.32
        x += np.sin(2 * np.pi * 190 * np.arange(m) / SR) * np.linspace(1, 0, m) ** 4 * 0.2
        trk.add(t0 + s * STEP, x)
    for s in range(0, 16, 2):
        m = int(0.03 * SR)
        nz = rng.uniform(-1, 1, m)
        nz = nz - np.convolve(nz, np.ones(4) / 4, mode="same")
        trk.add(t0 + s * STEP, nz * np.linspace(1, 0, m) * 0.14)


def compose():
    bar = STEP * 16
    order = [("A", 0), ("A", 1), ("B", 1), ("A", 1)]
    total = bar * 8 * len(order)
    trk = Track(total)
    t = 0.0
    for sec, variant in order:
        roots = SECTION_A if sec == "A" else SECTION_B
        leads = LEAD_A if sec == "A" else LEAD_B
        for r, l in zip(roots, leads):
            # first pass of A: lead an octave lower for an intro feel
            lead = [None if n is None else n - (12 if (sec == "A" and variant == 0) else 0) for n in l]
            render_bar(trk, t, r, lead, variant)
            t += bar
    out = trk.buf[: int(total * SR)]
    # gentle stereo-free echo for space
    d = int(STEP * 3 * SR)
    out[d:] += 0.18 * out[:-d]
    return out


if __name__ == "__main__":
    x = compose()
    x = x / np.max(np.abs(x)) * 0.89
    path = Path(sys.argv[1])
    wav = path.with_suffix(".wav")
    with wave.open(str(wav), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes((x * 32767).astype(np.int16).tobytes())
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(wav), "-codec:a", "libmp3lame", "-q:a", "3",
                    "-metadata", "title=Dragon Star Battle", "-metadata", "comment=CC0 - generated for Dragon Star",
                    str(path)], check=True)
    wav.unlink()
    print(path, round(len(x) / SR, 1), "s")
