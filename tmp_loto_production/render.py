import asyncio
import importlib
import json
import subprocess
import sys
import wave
from pathlib import Path

import edge_tts

VOICE = "es-ES-ElviraNeural"
ROOT = Path("tmp_loto_production")
OUT_ROOT = ROOT / "output"
OUT_ROOT.mkdir(parents=True, exist_ok=True)

PROFILES = {
    "intro":    {"rate": "+7%", "pitch": "+3Hz", "volume": "+2%"},
    "explain":  {"rate": "+4%", "pitch": "+1Hz", "volume": "+1%"},
    "key":      {"rate": "-2%", "pitch": "-1Hz", "volume": "+3%"},
    "question": {"rate": "+2%", "pitch": "+4Hz", "volume": "+2%"},
    "serious":  {"rate": "-5%", "pitch": "-3Hz", "volume": "+1%"},
    "enum1":    {"rate": "+1%", "pitch": "+3Hz", "volume": "+2%"},
    "enum2":    {"rate": "+1%", "pitch": "+2Hz", "volume": "+2%"},
    "enum3":    {"rate": "+1%", "pitch": "+1Hz", "volume": "+2%"},
    "enum4":    {"rate": "0%",  "pitch": "-1Hz", "volume": "+2%"},
    "enum5":    {"rate": "-1%", "pitch": "-2Hz", "volume": "+3%"},
    "close":    {"rate": "+3%", "pitch": "+1Hz", "volume": "+2%"},
}


def run(cmd):
    subprocess.run(cmd, check=True)


def read_wav(path):
    with wave.open(str(path), "rb") as w:
        params = w.getparams()
        frames = w.readframes(w.getnframes())
    return params, frames


def wav_duration(path):
    with wave.open(str(path), "rb") as w:
        return w.getnframes() / w.getframerate()


async def synthesize(text, profile, mp3_path):
    cfg = PROFILES[profile]
    communicate = edge_tts.Communicate(
        text,
        VOICE,
        rate=cfg["rate"],
        pitch=cfg["pitch"],
        volume=cfg["volume"],
    )
    await communicate.save(str(mp3_path))


async def render_slide(part_dir, slide):
    slide_no = int(slide["slide"])
    target = float(slide["target"])
    pre = float(slide.get("pre", 3.0))
    segments = slide["segments"]
    seg_wavs = []

    for idx, seg in enumerate(segments, 1):
        profile = seg.get("mode", "explain")
        pause = float(seg.get("pause", 0.55))
        mp3 = part_dir / f"s{slide_no:02d}_{idx:02d}.mp3"
        wav = part_dir / f"s{slide_no:02d}_{idx:02d}.wav"
        await synthesize(seg["text"], profile, mp3)
        run([
            "ffmpeg", "-y", "-loglevel", "error", "-i", str(mp3),
            "-ac", "1", "-ar", "24000", "-c:a", "pcm_s16le", str(wav),
        ])
        seg_wavs.append((wav, pause))

    raw = part_dir / f"slide_{slide_no:02d}_raw.wav"
    with wave.open(str(raw), "wb") as out:
        out.setnchannels(1)
        out.setsampwidth(2)
        out.setframerate(24000)
        out.writeframes(b"\x00\x00" * int(24000 * pre))
        for wav_path, pause in seg_wavs:
            _, frames = read_wav(wav_path)
            out.writeframes(frames)
            out.writeframes(b"\x00\x00" * int(24000 * pause))

    raw_dur = wav_duration(raw)
    if raw_dur > target:
        target = raw_dur + 2.0
    tail = max(0.0, target - raw_dur)

    final_wav = part_dir / f"slide_{slide_no:02d}.wav"
    with wave.open(str(final_wav), "wb") as out:
        out.setnchannels(1)
        out.setsampwidth(2)
        out.setframerate(24000)
        _, frames = read_wav(raw)
        out.writeframes(frames)
        out.writeframes(b"\x00\x00" * int(24000 * tail))

    final_mp3 = part_dir / f"slide_{slide_no:02d}.mp3"
    run([
        "ffmpeg", "-y", "-loglevel", "error", "-i", str(final_wav),
        "-af", "loudnorm=I=-16:TP=-1.5:LRA=9",
        "-ar", "48000", "-ac", "1", "-c:a", "libmp3lame", "-b:a", "144k",
        str(final_mp3),
    ])

    return {
        "slide": slide_no,
        "target_requested": float(slide["target"]),
        "duration": round(wav_duration(final_wav), 3),
        "voice": VOICE,
    }


async def main():
    if len(sys.argv) != 2:
        raise SystemExit("usage: render.py <part-number>")
    part = int(sys.argv[1])
    module = importlib.import_module(f"tmp_loto_production.part{part}")
    slides = module.SLIDES
    part_dir = OUT_ROOT / f"video_{part}"
    part_dir.mkdir(parents=True, exist_ok=True)

    manifest = []
    for slide in slides:
        print(f"Rendering slide {slide['slide']} / part {part}", flush=True)
        manifest.append(await render_slide(part_dir, slide))

    (part_dir / "manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (part_dir / "VOICE_LOCK.txt").write_text(VOICE, encoding="utf-8")
    transcript = []
    for slide in slides:
        transcript.append(f"DIAPOSITIVA {slide['slide']}")
        transcript.extend(seg["text"] for seg in slide["segments"])
        transcript.append("")
    (part_dir / "TRANSCRIPCION.txt").write_text("\n".join(transcript), encoding="utf-8")
    print("DONE", part, VOICE, flush=True)


if __name__ == "__main__":
    asyncio.run(main())
