#!/usr/bin/env python3
"""Key-&-Fill-Ausgang: Grafik (Bauchbinde) ohne Programmbild auf ZWEI SDI-Ausgaengen.

Fill = die Grafik auf Schwarz (damit "premultiplied": Farbe schon mit der Deckkraft multipliziert), Key = die Deckkraft der Grafik als
Graustufenbild (Weiss = deckt, Schwarz = durchsichtig, legaler Bereich 16 bis 235). Ein Bildmischer legt Fill mit Key ueber sein Programmbild.

Beide Ausgaenge haengen an EINEM Bilderzeuger (ein tee), laufen in einer Pipeline und sind dadurch bildgenau gleich; die Grafik wird in beiden
Zweigen mit demselben Zeitgeber bewegt. Der Bilderzeuger liefert schwarze Bilder mit der Halbbildrate (bei 1080i50: 50 Bilder/s, danach
`interlace` zu 25 Vollbildern mit zwei zeitversetzten Halbbildern, wie im Empfaenger) bzw. mit der Bildrate des progressiven Modus.

Steuerung wie beim Empfaenger (scripts/srt_to_sdi_receiver.py --lt-file): JSON-Steuerdatei (png = Fill-Grafik, png_key = weisse Silhouette mit
derselben Deckkraft, Lage, Animation, seq/cmd), die jede halbe Sekunde gelesen wird. Der Zustand (hidden/in/shown/out) steht in der
Statistikdatei unter "lowerthird". SIGTERM/SIGINT beenden das Programm; bei einem Pipeline-Fehler wird nach 2 s neu aufgebaut.
"""
import argparse
import json
import os
import re
import signal
import sys
import time

import gi

gi.require_version("Gst", "1.0")
from gi.repository import GLib, Gst  # noqa: E402

state = {"stopping": False, "loop": None, "pipe": None, "els": [], "lt_state": "hidden", "lt_progress": 0.0, "lt_seq": None,
         "lt_anim_id": 0, "lt_hold_id": 0, "lt_cfg": None, "lt_png": None, "lt_png_key": None, "lt_stamp": None}


def log(msg: str) -> None:
    print(msg, flush=True)


def mode_rate(mode: str):
    """(Bilder pro Sekunde der Erzeugung, interlaced?) aus dem Modusnamen: 1080i50 -> ("50/1", True), 1080p25 -> ("25/1", False)."""
    m = re.search(r"(\d{3,4})([ip])(\d{2,4})$", mode or "")
    if not m:
        raise SystemExit(f"Unbekannter Modus: {mode}")
    table = {"2398": "24000/1001", "2997": "30000/1001", "5994": "60000/1001"}
    n = m.group(3)
    if m.group(2) == "i":
        # i50 -> 50 Halbbilder/s, i5994 -> 59,94, i60 -> 60 (Halbbildrate = Zahl im Namen)
        return {"50": "50/1", "5994": "60000/1001", "60": "60/1"}.get(n, f"{n}/1"), True
    return table.get(n, f"{n}/1"), False


def ease_out(p: float) -> float:
    return 1.0 - (1.0 - p) ** 3


def ease_in(p: float) -> float:
    return p ** 3


def lt_rest(a, cfg) -> tuple:
    w, h = int(cfg.get("w", 0)), int(cfg.get("h", 0))
    mx, my = round(a.frame_width * 0.04), round(a.frame_height * 0.05)
    vert, _, horiz = str(cfg.get("pos", "bottom-left")).partition("-")
    x = {"left": mx, "center": (a.frame_width - w) // 2, "right": a.frame_width - w - mx}.get(horiz, mx)
    y = my if vert == "top" else a.frame_height - h - my
    return x, y


def lt_place(a, cfg, progress: float, direction: str) -> None:
    """Grafik in BEIDEN Zweigen (Fill und Key) fuer den Animationsfortschritt setzen."""
    if not state["els"]:
        return
    w, h = int(cfg.get("w", 0)), int(cfg.get("h", 0))
    x, y = lt_rest(a, cfg)
    anim = str(cfg.get("anim", "slide-left"))
    e = ease_out(progress) if direction == "in" else 1.0 - ease_in(1.0 - progress)
    alpha = 1.0
    if anim == "fade":
        alpha = e
    elif anim == "slide-left":
        x = round(-w + (x + w) * e)
    elif anim == "slide-right":
        x = round(a.frame_width + (x - a.frame_width) * e)
    elif anim == "slide-up":
        y = round(a.frame_height + (y - a.frame_height) * e)
    elif anim == "slide-down":
        y = round(-h + (y + h) * e)
    if progress <= 0.0:
        alpha = 0.0
    alpha = max(0.0, min(1.0, alpha))
    for el in state["els"]:
        el.set_property("offset-x", x)
        el.set_property("offset-y", y)
        el.set_property("alpha", alpha)


def lt_stop_anim() -> None:
    for k in ("lt_anim_id", "lt_hold_id"):
        if state.get(k):
            GLib.source_remove(state[k])
            state[k] = 0


def lt_animate(a, direction: str, cfg=None, done=None) -> None:
    cfg = cfg if cfg is not None else (state.get("lt_cfg") or {})
    if not cfg.get("png"):
        return
    lt_stop_anim()
    dur = max(100, int(cfg.get("dur", 700))) / 1000.0
    start = time.monotonic()
    p0 = state.get("lt_progress", 0.0)
    state["lt_state"] = "in" if direction == "in" else "out"
    target = 1.0 if direction == "in" else 0.0

    def step() -> bool:
        t = min(1.0, (time.monotonic() - start) / dur)
        prog = p0 + (target - p0) * t
        state["lt_progress"] = prog
        lt_place(a, cfg, prog, direction)
        if t >= 1.0:
            state["lt_anim_id"] = 0
            state["lt_state"] = "shown" if direction == "in" else "hidden"
            if done is not None:
                done()
                return False
            if direction == "in":
                hold = int(cfg.get("hold", 0))
                if hold > 0:
                    state["lt_hold_id"] = GLib.timeout_add_seconds(hold, lambda: (state.__setitem__("lt_hold_id", 0), lt_animate(a, "out"), False)[2])
            return False
        return True

    state["lt_anim_id"] = GLib.timeout_add(20, step)


def apply_lowerthird(a, force=False) -> None:
    els = state["els"]
    if len(els) != 2 or not a.lt_file:
        return
    try:
        st = os.stat(a.lt_file)
    except OSError:
        return
    stamp = (st.st_mtime_ns, st.st_size)
    if not force and state.get("lt_stamp") == stamp:
        return
    try:
        with open(a.lt_file, encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError) as e:
        state["lt_stamp"] = stamp
        log(f"Bauchbinden-Steuerdatei nicht lesbar: {e}")
        return
    state["lt_stamp"] = stamp
    png = str(cfg.get("png") or "")
    png_key = str(cfg.get("png_key") or "")
    path, path_key = png.split("?", 1)[0], png_key.split("?", 1)[0]

    def load_png() -> None:
        try:
            els[0].set_property("location", path)
            els[1].set_property("location", path_key)
            state["lt_png"], state["lt_png_key"] = png, png_key
            log(f"Bauchbinde: Grafik geladen ({cfg.get('w')}x{cfg.get('h')}, {cfg.get('anim')}, {cfg.get('pos')})")
        except Exception as e:  # noqa: BLE001
            log(f"Bauchbinden-Grafik nicht ladbar: {e}")

    new_png = bool(path and path_key) and (png != state.get("lt_png") or png_key != state.get("lt_png_key"))
    seq_new = state.get("lt_seq") is not None and int(cfg.get("seq", 0)) != state["lt_seq"]
    # Neuer Inhalt bei sichtbarer Grafik und "show": erst die alte ausfliegen lassen, dann die neue einfliegen
    if new_png and seq_new and str(cfg.get("cmd")) == "show" and state["lt_state"] in ("shown", "in"):
        state["lt_seq"] = int(cfg.get("seq", 0))
        old_cfg = dict(state.get("lt_cfg") or {})

        def then_in() -> None:
            load_png()
            state["lt_cfg"] = cfg
            state["lt_progress"] = 0.0
            lt_place(a, cfg, 0.0, "in")
            lt_animate(a, "in")

        lt_animate(a, "out", cfg=old_cfg, done=then_in)
        return
    if new_png:
        load_png()
    state["lt_cfg"] = cfg
    if state["lt_state"] in ("shown", "in"):
        lt_place(a, cfg, state.get("lt_progress", 1.0) if state["lt_state"] == "in" else 1.0, "in")
    seq = int(cfg.get("seq", 0))
    if state.get("lt_seq") is None:
        state["lt_seq"] = seq
        return
    if seq != state["lt_seq"]:
        state["lt_seq"] = seq
        cmd = str(cfg.get("cmd", "none"))
        if cmd == "show":
            lt_animate(a, "in")
        elif cmd == "hide":
            lt_animate(a, "out")


def build(a):
    fps, interlaced = mode_rate(a.mode)
    gfx = ("gdkpixbufoverlay name={n} positioning-mode=pixels-absolute alpha=0.0 offset-x=-100000 offset-y=0 ! ")
    # Fertig konvertieren: UYVY mit BT.709; Zeilensprung-Modi: 50 Bilder/s zu 25 Vollbildern mit zwei Halbbildern (1:1)
    tail = "videoconvert ! video/x-raw,format=UYVY,colorimetry=bt709 ! "
    if interlaced:
        tail += "interlace field-pattern=0 top-field-first=true ! "
    if a.null_sinks and a.snapshot_dir:
        def sink(name):
            return (f"videorate drop-only=true ! video/x-raw,framerate={a.snapshot_fps}/1 ! videoconvert ! jpegenc ! "
                    f"multifilesink name={name} location={a.snapshot_dir}/{name}_%05d.jpg sync=true")
    elif a.null_sinks:
        def sink(name):
            return f"fakesink name={name} sync=true"
    else:
        def sink(name):
            dev = a.fill_device if name == "fillsink" else a.key_device
            return f"decklinkvideosink name={name} device-number={dev} mode={a.mode} profile={a.profile}"
    desc = (
        f"videotestsrc is-live=true pattern=solid-color foreground-color=0xFF000000 ! "
        f"video/x-raw,format=BGRA,width={a.frame_width},height={a.frame_height},framerate={fps} ! tee name=t  "
        f"t. ! queue ! {gfx.format(n='ltf')}{tail}{sink('fillsink')}  "
        f"t. ! queue ! {gfx.format(n='ltk')}{tail}{sink('keysink')}"
    )
    pipe = Gst.parse_launch(desc)
    state["els"] = [pipe.get_by_name("ltf"), pipe.get_by_name("ltk")]
    lt_stop_anim()
    state["lt_state"], state["lt_progress"], state["lt_png"], state["lt_png_key"], state["lt_stamp"] = "hidden", 0.0, None, None, None
    apply_lowerthird(a, force=True)
    return pipe


def struct_numbers(st) -> dict:
    out = {}
    if st is None:
        return out
    for i in range(st.n_fields()):
        name = st.nth_field_name(i)
        try:
            val = st.get_value(name)
        except Exception:  # noqa: BLE001
            continue
        if isinstance(val, (int, float)) and not isinstance(val, bool):
            out[name.replace("-", "_")] = val
    return out


def write_stats(a) -> bool:
    pipe = state.get("pipe")
    if pipe is None or not a.stats_file:
        return True
    data = {"time": time.time(), "srt": {}, "queues": {}, "sinks": {}, "protocol": "fillkey",
            "lowerthird": {"state": state.get("lt_state", "hidden")}}
    for name in ("fillsink", "keysink"):
        sink = pipe.get_by_name(name)
        if sink is not None:
            try:
                data["sinks"][name] = struct_numbers(sink.get_property("stats"))
            except Exception:  # noqa: BLE001
                pass
    tmp = a.stats_file + ".tmp"
    try:
        with open(tmp, "w") as f:
            json.dump(data, f)
        os.replace(tmp, a.stats_file)
    except OSError as e:
        log(f"Statistik nicht schreibbar: {e}")
    return True


def poll(a) -> bool:
    if state.get("pipe") is not None:
        apply_lowerthird(a)
    return True


def run_once(a) -> int:
    pipe = build(a)
    state["pipe"] = pipe
    stats_timer = GLib.timeout_add_seconds(1, write_stats, a)
    poll_timer = GLib.timeout_add(500, poll, a)
    loop = GLib.MainLoop()
    state["loop"] = loop
    code = {"value": 0}

    def on_message(_bus, msg):
        if msg.type == Gst.MessageType.ERROR:
            err, dbg = msg.parse_error()
            log(f"FEHLER: {err.message} ({dbg})")
            code["value"] = 1
            loop.quit()
        elif msg.type == Gst.MessageType.EOS:
            loop.quit()

    bus = pipe.get_bus()
    bus.add_signal_watch()
    bus.connect("message", on_message)
    pipe.set_state(Gst.State.PLAYING)
    try:
        loop.run()
    finally:
        GLib.source_remove(stats_timer)
        GLib.source_remove(poll_timer)
        lt_stop_anim()
        state["els"] = []
        state["pipe"] = None
        pipe.set_state(Gst.State.NULL)  # gibt beide Ausgaenge frei
        bus.remove_signal_watch()
        state["loop"] = None
    return code["value"]


def pause(seconds: float) -> None:
    loop = GLib.MainLoop()
    state["loop"] = loop
    GLib.timeout_add(int(seconds * 1000), loop.quit)
    loop.run()
    state["loop"] = None


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--fill-device", type=int, required=True, help="DeckLink-Geraet fuer Fill (0 bis 3)")
    ap.add_argument("--key-device", type=int, required=True, help="DeckLink-Geraet fuer Key (0 bis 3)")
    ap.add_argument("--mode", required=True, help="Ausgangsmodus beider Ausgaenge, z. B. 1080i50")
    ap.add_argument("--profile", default="default")
    ap.add_argument("--frame-width", type=int, default=1920)
    ap.add_argument("--frame-height", type=int, default=1080)
    ap.add_argument("--lt-file", required=True, help="JSON-Steuerdatei der Bauchbinde (wird laufend gelesen)")
    ap.add_argument("--stats-file", help="JSON-Datei fuer Zustand und Ausgabe-Zaehler")
    ap.add_argument("--null-sinks", action="store_true", help="fakesink statt DeckLink (nur Tests ohne Karte)")
    ap.add_argument("--snapshot-dir", help="nur mit --null-sinks: je Sekunde ein JPEG von Fill und Key dorthin (Tests)")
    ap.add_argument("--snapshot-fps", type=int, default=1)
    a = ap.parse_args()
    if a.fill_device == a.key_device:
        ap.error("Fill und Key brauchen zwei verschiedene Ausgaenge")
    Gst.init(None)

    def on_signal(*_):
        state["stopping"] = True
        if state["loop"] is not None:
            state["loop"].quit()
        return True

    GLib.unix_signal_add(GLib.PRIORITY_HIGH, signal.SIGTERM, on_signal)
    GLib.unix_signal_add(GLib.PRIORITY_HIGH, signal.SIGINT, on_signal)
    try:
        while not state["stopping"]:
            rc = run_once(a)
            if state["stopping"]:
                break
            log(f"Pipeline beendet (Code {rc}), Neuaufbau in 2 s")
            pause(2)
    finally:
        if a.stats_file:
            try:
                os.remove(a.stats_file)
            except OSError:
                pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
