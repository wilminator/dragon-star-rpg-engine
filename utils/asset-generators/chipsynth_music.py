"""Re-render the engine's chipsynth music (CC0, original work) from its MML sources.

Each app/music/src/NAME.mml becomes app/music/NAME.mp3. The looping tunes are
cut at the loop end (--tail 0) so a player can loop the file; the once-only
game over keeps its ring-out. Every tune is normalized to -3 dBFS: headroom for
the MP3 codec's overshoot, and a level near the older battle loop anger.mp3.
Rendering is deterministic: the same source gives the same bytes.

Needs chipsynth (https://github.com/wilminator/chipsynth) with numpy, scipy
and soundfile on the path:

    PYTHONPATH=/path/to/chipsynth python3 utils/asset-generators/chipsynth_music.py [NAME...]
"""
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "app" / "music" / "src"
OUT = ROOT / "app" / "music"

# name -> True when the tune loops (rendered cut at the loop end), False when it plays once
TUNES = {
    "dawn_watch": True,    # title
    "cold_hearth": False,  # game over
    "open_sky": True,      # map / hub
    "quickblade": True,    # combat
    "wyrmfire": True,      # boss
}


def render(name):
    loops = TUNES[name]
    cmd = [sys.executable, "-m", "chipsynth", "export-song", str(SRC / f"{name}.mml"),
           "-o", str(OUT / f"{name}.mp3"), "--normalize", "--peak", "-3"]
    if loops:
        cmd += ["--tail", "0"]
    print(" ".join(cmd[1:]))
    subprocess.run(cmd, check=True)


if __name__ == "__main__":
    names = sys.argv[1:] or list(TUNES)
    unknown = [n for n in names if n not in TUNES]
    if unknown:
        sys.exit("unknown tune(s): %s (known: %s)" % (", ".join(unknown), ", ".join(TUNES)))
    for name in names:
        render(name)
