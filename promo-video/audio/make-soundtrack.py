"""
Builds the promo video's soundtrack: Zoe narration + a minimal lo-fi pulse +
soft whooshes and tactile mouse clicks. Everything is original: the voice is
the local macOS "Zoe (Premium)" voice (offline, nothing sent anywhere), and the
music and sound effects are synthesised here from plain waveforms - no
samples, no third-party music. Deterministic apart from the TTS engine.

Narration text and start times come from audio/narration.json, which is also
the source of the on-screen captions in index.html (they must stay identical).

Usage (from promo-video/, macOS with the Zoe (Premium) voice installed):
  python3 audio/make-soundtrack.py
Writes assets/audio/soundtrack.m4a (AAC, 48 kHz stereo).

Mix: voice clearly on top; music about 14 dB under the voice and ducked a
further ~7 dB while she speaks; whooshes and clicks between the two. The
whole mix is normalised to -18 LUFS with a -2 dBTP ceiling. Captions carry
every word, so the video works the same muted.
"""

import json
import math
import os
import struct
import subprocess
import sys
import tempfile
import wave

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT = os.path.join(ROOT, "assets", "audio", "soundtrack.m4a")

SR = 48000
DUR = 30.0
N = int(SR * DUR)


def run(*cmd):
    return subprocess.run(cmd, check=True, capture_output=True, text=True)


def read_mono(path):
    with wave.open(path, "rb") as w:
        assert w.getnchannels() == 1 and w.getsampwidth() == 2 and w.getframerate() == SR
        raw = w.readframes(w.getnframes())
    return [s / 32768 for s in struct.unpack(f"<{len(raw) // 2}h", raw)]


def rms(xs):
    return math.sqrt(sum(x * x for x in xs) / max(1, len(xs)))


class Noise:
    """Fixed LCG sequence in [-1, 1]: noise-like but identical every run."""

    def __init__(self, seed):
        self.s = seed

    def next(self):
        self.s = (1103515245 * self.s + 12345) & 0x7FFFFFFF
        return self.s / 0x3FFFFFFF - 1.0


class Bandpass:
    """RBJ biquad band-pass (constant 0 dB peak gain)."""

    def __init__(self, f0, q):
        w0 = 2 * math.pi * f0 / SR
        alpha = math.sin(w0) / (2 * q)
        a0 = 1 + alpha
        self.b0, self.b2 = alpha / a0, -alpha / a0
        self.a1, self.a2 = -2 * math.cos(w0) / a0, (1 - alpha) / a0
        self.x1 = self.x2 = self.y1 = self.y2 = 0.0

    def __call__(self, x):
        y = self.b0 * x + self.b2 * self.x2 - self.a1 * self.y1 - self.a2 * self.y2
        self.x2, self.x1, self.y2, self.y1 = self.x1, x, self.y1, y
        return y


# ---------------------------------------------------------------- narration
narr = json.load(open(os.path.join(HERE, "narration.json"), encoding="utf-8"))
voice = [0.0] * N
speaking = []  # (start, end) seconds
with tempfile.TemporaryDirectory() as tmp:
    for line in narr["lines"]:
        aiff, wav = os.path.join(tmp, line["id"] + ".aiff"), os.path.join(tmp, line["id"] + ".wav")
        run("say", "-v", narr["voice"], "-o", aiff, line["text"])
        trim = "silenceremove=start_periods=1:start_threshold=-50dB,areverse,silenceremove=start_periods=1:start_threshold=-50dB,areverse"
        run("ffmpeg", "-v", "error", "-y", "-i", aiff, "-af", trim, "-ar", str(SR), "-ac", "1", "-c:a", "pcm_s16le", wav)
        samples = read_mono(wav)
        start = int(line["at"] * SR)
        if start + len(samples) > N:
            sys.exit(f"{line['id']} runs past the end of the video ({line['at'] + len(samples) / SR:.2f}s)")
        for j, v in enumerate(samples):
            voice[start + j] += v
        speaking.append((line["at"], line["at"] + len(samples) / SR))
voice_level = rms([voice[i] for a, b in speaking for i in range(int(a * SR), int(b * SR))])

# ---------------------------------------------------------------- lo-fi pulse
BPM = 84
BEAT = 60 / BPM
BAR = 4 * BEAT
CHORDS = [  # (from, to, frequencies) following the three scenes
    (0.0, 7.0, [110.00, 164.81, 196.00, 261.63]),    # A minor 7: the guess
    (7.0, 21.0, [87.31, 130.81, 164.81, 220.00]),    # F major 7: the app
    (21.0, 30.0, [130.81, 196.00, 293.66, 329.63]),  # C add9: the projection
]
mL, mR = [0.0] * N, [0.0] * N


def chord_at(t):
    for a, b, f in CHORDS:
        if a <= t < b:
            return f
    return CHORDS[-1][2]


# Soft kick on every beat, quieter on 2 and 4.
for k in range(int(DUR / BEAT) + 1):
    at = k * BEAT
    g = 0.55 if k % 2 == 0 else 0.35
    for j in range(int(0.30 * SR)):
        i = int(at * SR) + j
        if i >= N:
            break
        t = j / SR
        f = 48 + 42 * math.exp(-t / 0.03)
        v = g * math.sin(2 * math.pi * f * t) * math.exp(-t / 0.11)
        mL[i] += v
        mR[i] += v

# Very faint off-beat hat: band-passed noise, a few ms long.
hat = Bandpass(7000, 0.9)
noise = Noise(5)
for k in range(int(DUR / BEAT) + 1):
    at = k * BEAT + BEAT / 2
    for j in range(int(0.05 * SR)):
        i = int(at * SR) + j
        if i >= N:
            break
        v = 0.12 * hat(noise.next()) * math.exp(-j / SR / 0.012)
        mL[i] += v * 0.8
        mR[i] += v * 1.2

# Warm electric-piano-like chord, struck at each bar (and at each scene change).
strikes = sorted({round(k * BAR, 4) for k in range(int(DUR / BAR) + 1)} | {a for a, _, _ in CHORDS})
for at in strikes:
    freqs = chord_at(at + 0.01)
    nxt = next((s for s in strikes if s > at), DUR)
    for j in range(int((nxt - at + 0.4) * SR)):
        i = int(at * SR) + j
        if i >= N:
            break
        t = j / SR
        env = min(1.0, t / 0.015) * math.exp(-t / 1.6)
        if at + t > nxt:  # short release into the next strike
            env *= max(0.0, 1 - (at + t - nxt) / 0.4)
        trem = 0.9 + 0.1 * math.sin(2 * math.pi * 4.2 * (at + t))
        sl = sr_ = 0.0
        for n, f in enumerate(freqs):
            base = math.sin(2 * math.pi * f * t + n) + 0.3 * math.sin(4 * math.pi * f * t + n)
            sl += base * (1.0 if n % 2 == 0 else 0.8)
            sr_ += base * (0.8 if n % 2 == 0 else 1.0)
        mL[i] += 0.06 * env * trem * sl
        mR[i] += 0.06 * env * trem * sr_

# Warmth: gentle one-pole low-pass over the whole music bus.
lpL = lpR = 0.0
for i in range(N):
    lpL += 0.18 * (mL[i] - lpL)
    lpR += 0.18 * (mR[i] - lpR)
    mL[i], mR[i] = lpL, lpR

# Music sits ~14 dB under the voice, then ducks ~7 dB more while she speaks.
music_level = rms([(mL[i] + mR[i]) / 2 for i in range(0, N, 7)]) or 1e-9
mgain = voice_level * 10 ** (-14 / 20) / music_level
duck = [0.0] * N
for a, b in speaking:
    for i in range(max(0, int((a - 0.12) * SR)), min(N, int((b + 0.35) * SR))):
        t = i / SR
        duck[i] = max(duck[i], min(1.0, (t - (a - 0.12)) / 0.12, ((b + 0.35) - t) / 0.35))
for i in range(N):
    g = mgain * (1 - 0.55 * duck[i])
    mL[i] *= g
    mR[i] *= g

# ---------------------------------------------------------------- sound effects
sL, sR = [0.0] * N, [0.0] * N


def place(i, v, pan):
    if 0 <= i < N:
        sL[i] += v * math.cos((pan + 1) * math.pi / 4)
        sR[i] += v * math.sin((pan + 1) * math.pi / 4)


def whoosh(at, dur, level, seed, pan_from=-0.5, pan_to=0.5):
    """Soft clean whoosh: band-passed noise, smooth swell, gentle pan."""
    noise, bp = Noise(seed), Bandpass(900, 0.7)
    for j in range(int(dur * SR)):
        x = j / (dur * SR)
        env = math.sin(math.pi * x) ** 2
        place(int(at * SR) + j, level * env * bp(noise.next()), pan_from + (pan_to - pan_from) * x)


def mouse_click(at, level, pan=0.0):
    """Tactile mouse click: a short plastic 'press' and a smaller 'release'."""
    for offset, g in ((0.0, 1.0), (0.055, 0.45)):
        noise, body, snap = Noise(int(at * 1000) + int(offset * 100)), Bandpass(2300, 3.5), Bandpass(5200, 2.0)
        for j in range(int(0.04 * SR)):
            t = j / SR
            exc = noise.next() * math.exp(-t / 0.0012)
            v = 0.8 * body(exc) * math.exp(-t / 0.006) + 0.35 * snap(exc)
            place(int((at + offset) * SR) + j, level * g * v, pan)


fx = voice_level  # effects are set relative to the voice
whoosh(0.30, 0.45, fx * 0.6, 3, -0.2, 0.2)      # sticky note slides in
mouse_click(1.60, fx * 0.9, -0.3)               # tab switch: Meta Ads
mouse_click(3.20, fx * 0.9, 0.0)                # tab switch: LinkedIn Ads
mouse_click(4.80, fx * 0.9, 0.3)                # tab switch: ChatGPT Ads
whoosh(6.75, 0.65, fx * 1.0, 7)                 # scene 1 -> the app
mouse_click(10.10, fx * 1.4, 0.15)              # cursor click: metric -> CPC
mouse_click(13.35, fx * 1.4, 0.25)              # cursor click: industry
whoosh(14.80, 1.30, fx * 0.45, 11, 0.0, 0.0)    # pan down to the drill-down
whoosh(16.90, 1.10, fx * 0.45, 13, 0.2, -0.2)   # zoom in on the tags
whoosh(20.80, 0.65, fx * 1.0, 17)               # -> illustrative projection
whoosh(27.15, 0.65, fx * 1.0, 19)               # -> end card

# ---------------------------------------------------------------- mix + normalise
with tempfile.TemporaryDirectory() as tmp:
    raw = os.path.join(tmp, "mix.wav")
    frames = bytearray()
    for i in range(N):
        t = i / SR
        fade = min(1.0, t / 0.25, (DUR - t) / 0.4)
        for v in (voice[i] + mL[i] + sL[i], voice[i] + mR[i] + sR[i]):
            frames += struct.pack("<h", int(max(-1.0, min(1.0, v * fade)) * 32767))
    with wave.open(raw, "wb") as w:
        w.setnchannels(2)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(bytes(frames))

    target = "I=-18:TP=-2:LRA=11"
    measured = run("ffmpeg", "-hide_banner", "-nostats", "-i", raw, "-af", f"loudnorm={target}:print_format=json", "-f", "null", "-").stderr
    start = measured.rindex("{")
    m = json.loads(measured[start:measured.index("}", start) + 1])
    norm = (f"loudnorm={target}:measured_I={m['input_i']}:measured_TP={m['input_tp']}:measured_LRA={m['input_lra']}:"
            f"measured_thresh={m['input_thresh']}:offset={m['target_offset']}:linear=true")
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    run("ffmpeg", "-v", "error", "-y", "-i", raw, "-af", norm, "-ar", str(SR), "-c:a", "aac", "-b:a", "160k", OUT)
print(f"wrote {OUT}")
for a, b in speaking:
    print(f"  voice {a:5.2f}-{b:5.2f}s")
