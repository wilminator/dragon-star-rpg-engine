"""Render Chopin's Funeral March theme (public domain composition) as an original recording (CC0).

Usage: python3 funeral.py OUT.mp3
"""
import subprocess
import sys
import wave
from pathlib import Path

import numpy as np

SR = 44100
Q = 1.15  # seconds per quarter note (Lento)


def midi(n):
    return 440 * 2 ** ((n - 69) / 12)


def piano(f, dur, vel=1.0):
    n = int(SR * (dur + 1.2))
    t = np.arange(n) / SR
    x = sum(a * np.sin(2 * np.pi * f * k * t) for k, a in ((1, 1.0), (2, 0.45), (3, 0.2), (4, 0.1)))
    env = np.exp(-t * 2.2) * np.minimum(1, t / 0.006)
    env[int(SR * dur):] *= np.exp(-(t[int(SR * dur):] - dur) * 9)
    return x * env * vel


def add(buf, t0, x):
    i = int(t0 * SR)
    buf[i:i + len(x)] += x[: max(0, len(buf) - i)]


# B-flat minor. Melody as (midi note, quarter-note length) per bar.
BAR_A = [(70, 1), (70, 0.75), (70, 0.25), (70, 2)]
BAR_B = [(73, 1), (72, 0.75), (72, 0.25), (70, 0.75), (70, 0.25), (69, 0.75), (70, 0.25)]
MELODY = [BAR_A, BAR_B, BAR_A, BAR_B, [(n + 12, d) for n, d in BAR_A], [(n + 12, d) for n, d in BAR_B], BAR_A, [(70, 4)]]
# Tolling left hand: B-flat minor and G-flat major fifths alternating each beat.
LEFT = [(46, 53), (42, 49)]


def render():
    total = len(MELODY) * 4 * Q + 2.5
    buf = np.zeros(int(SR * total))
    t = 0.0
    for b, bar in enumerate(MELODY):
        for beat in range(4):
            lo, hi = LEFT[beat % 2]
            for n in (lo - 12, lo, hi):
                add(buf, t + beat * Q, piano(midi(n), Q * 0.95, 0.32))
        bt = t
        for n, d in bar:
            add(buf, bt, piano(midi(n), d * Q * 0.92, 0.55))
            add(buf, bt, piano(midi(n - 12), d * Q * 0.92, 0.18))
            bt += d * Q
        t += 4 * Q
    # small room reverb
    for dly, g in ((0.031, 0.35), (0.053, 0.28), (0.089, 0.22), (0.137, 0.15)):
        k = int(dly * SR)
        buf[k:] += g * buf[:-k]
    return buf / np.max(np.abs(buf)) * 0.89


if __name__ == "__main__":
    x = render()
    path = Path(sys.argv[1])
    wav = path.with_suffix(".wav")
    with wave.open(str(wav), "wb") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((x * 32767).astype(np.int16).tobytes())
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(wav), "-codec:a", "libmp3lame", "-q:a", "3",
                    "-metadata", "title=Funeral March (Chopin, Op. 35)", "-metadata", "comment=Composition public domain; this recording CC0, generated for Dragon Star",
                    str(path)], check=True)
    wav.unlink()
    print(path, round(len(x) / SR, 1), "s")
