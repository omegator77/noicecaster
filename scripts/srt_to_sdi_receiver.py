#!/usr/bin/env python3
"""SRT-Empfang -> DeckLink-Ausgabe (Video + Audio) mit dynamischer Pad-Behandlung.

Wird von PipelineManager::buildReceiveCommand() gestartet. tsdemux legt seine Streams beim
Verbindungsaufbau neu an (audio_0_0042 -> audio_1_0042, video_1_0041); jedes neue Pad wird hier
selbst mit dem Ausgabezweig verbunden. Der Codec wird per decodebin erkannt (H.264/H.265/MPEG-2,
Audio u. a. AAC, AC3, SMPTE 302M).

Selbstueberwachung: Bricht die Pipeline mit einem Fehler ab (z. B. der Videoausgang meldet einen
Stream-Fehler, wenn der Sender neu startet und die Zeitstempel zurueckspringen), wird sie nach
kurzer Pause neu aufgebaut. SIGTERM/SIGINT beenden das Programm sofort und schliessen die
SRT-Verbindung sauber.
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

state = {"stopping": False, "loop": None, "pipe": None}


def log(msg: str) -> None:
    print(msg, flush=True)


def pad_info(name: str):
    """(Generation, PID) aus einem tsdemux-Pad-Namen wie audio_1_0042."""
    parts = name.split("_")
    try:
        return int(parts[1]), parts[-1]
    except (IndexError, ValueError):
        return 0, name


def interlaced_mode_rates(mode: str):
    """Fuer Halbbild-Modi wie 1080i50 / 1080i5994 / 1080i60: (Halbbildrate, Bildrate) als Bruch-Text, sonst None."""
    m = re.search(r"\d{3,4}i(\d{2,4})$", mode or "")
    if not m:
        return None
    return {"50": ("50/1", "25/1"), "5994": ("60000/1001", "30000/1001"), "60": ("60/1", "30/1")}.get(m.group(1))


def build(a):
    # --null-sinks: fakesink statt DeckLink-Ausgabe, nur zum Entwickeln und Testen ohne Karte
    vsink = ("fakesink name=vsink sync=true" if a.null_sinks
             else f"decklinkvideosink name=vsink device-number={a.device} mode={a.mode} profile={a.profile}")
    asink = ("fakesink name=asink sync=true" if a.null_sinks
             else f"decklinkaudiosink name=asink device-number={a.device}")
    # Videozweig: Der Ausgang laeuft in einem Halbbild-Modus (z. B. 1080i50), die Quelle kann aber progressiv sein
    # (1080p50, 1080p25, 23,98p ...). Ein DeckLink-Ausgang mit Halbbild-Modus nimmt kein progressives Bild an
    # ("not-negotiated"). Darum drei Wege, die input-selector vor dem Ausgang zusammenfuehrt; welcher gilt,
    # entscheidet on_video_pad nach dem Zeilensprung-Modus und der Bildrate der dekodierten Quelle:
    #   A  = unveraendert (Quelle bereits Halbbild, oder der Ausgangsmodus ist progressiv)
    #   B1 = progressiv mit Halbbildrate (50p/59,94p/60p): jedes zweite Bild wird ein Halbbild (1:1)
    #   B2 = progressiv mit Vollbildrate (25p/23,98p/30p ...): auf die Bildrate des Modus bringen, Halbbilder 2:2
    rates = interlaced_mode_rates(a.mode)
    video = (
        "queue name=vq ! decodebin name=vdec  "
        f"input-selector name=vsel ! {vsink}  "
        "videoconvert name=thA ! videoscale ! videorate name=tlastA ! vsel.  "
    )
    if rates:
        field_rate, frame_rate = rates
        video += (
            "videoconvert name=thB1 ! videoscale ! videorate ! "
            f"capsfilter caps=video/x-raw,framerate={field_rate} ! "
            "interlace name=tlastB1 field-pattern=0 top-field-first=true ! vsel.  "
            "videoconvert name=thB2 ! videoscale ! videorate ! "
            f"capsfilter caps=video/x-raw,framerate={frame_rate} ! "
            "interlace name=tlastB2 field-pattern=1 top-field-first=true ! vsel.  "
        )
    # Die DeckLink-Ausgabe kennt nur 2, 8 oder 16 Kanaele. Bei mehr als 2 Ausgabekanaelen den Ton DISKRET
    # abbilden (Kanal 1..N des Stroms auf Ausgang 1..N, der Rest bleibt still): ein reines audioconvert rechnet
    # 4.0 nach 7.1 um und verteilt den Ton als Lautsprecher-Signal auf alle Ausgaenge. channel-mask=0 =
    # unpositioniert, wie es der DeckLink-Treiber selbst fuer 8/16 Kanaele liefert.
    if a.audio_channels > 2:
        mix = "audiomixmatrix mode=first-channels channel-mask=0 ! "
        caps = f"audio/x-raw,format=S16LE,rate=48000,channels={a.audio_channels},channel-mask=(bitmask)0"
    else:
        mix = ""
        caps = f"audio/x-raw,format=S16LE,rate=48000,channels={a.audio_channels}"
    audio = (
        "queue name=aq max-size-time=1000000000 ! decodebin ! audioconvert ! audioresample ! "
        f"{mix}{caps} ! "
        f"{asink}"
    )
    # Abgriff fuer die Statistik: Kopie des empfangenen Transportstroms auf 127.0.0.1 (leckere Queue, damit ein
    # fehlender oder langsamer Leser den Empfang nie bremst; tsparse buendelt 7 TS-Pakete je Datagramm).
    # Der Empfangsweg haengt direkt am ersten tee-Zweig und bleibt damit so wie vorher.
    head = "srtsrc name=src ! "
    tap = ""
    if a.tap_port:
        head += "tee name=rxtap ! "
        tap = ("rxtap. ! queue leaky=downstream max-size-buffers=300 ! tsparse alignment=7 ! "
               f"udpsink host=127.0.0.1 port={a.tap_port} sync=false async=false")
    pipe = Gst.parse_launch(f"{head}tsdemux name=demux  {video}  {audio}  {tap}")

    src = pipe.get_by_name("src")
    src.set_property("uri", a.uri)
    src.set_property("latency", a.latency)
    src.set_property("keep-listening", True)
    if a.passphrase:
        src.set_property("passphrase", a.passphrase)
        src.set_property("pbkeylen", a.pbkeylen or 16)
    if a.streamid:
        src.set_property("streamid", a.streamid)

    demux = pipe.get_by_name("demux")
    targets = {"video": pipe.get_by_name("vq"), "audio": pipe.get_by_name("aq")}

    def on_pad_added(_el, pad):
        kind = pad.get_name().split("_", 1)[0]
        tgt = targets.get(kind)
        if tgt is None:
            log(f"Pad {pad.get_name()} ignoriert")
            return
        sink = tgt.get_static_pad("sink")
        peer = sink.get_peer()
        if peer is not None:
            # Pad-Namen: <Art>_<Generation>_<PID>, z. B. audio_0_0042. Legt tsdemux den Strom neu an (neue
            # Verbindung, Sender wechselt PIDs oder Programm), steigt die Generation, die neuen Pads kommen VOR
            # dem Entfernen der alten: die neuere Generation ersetzt die alte. Ein weiteres Pad mit gleicher
            # Generation und anderer PID ist eine zusaetzliche Spur (z. B. Stereo-Spuren): die erste bleibt am
            # Ausgang.
            old_gen, old_pid = pad_info(peer.get_name())
            new_gen, new_pid = pad_info(pad.get_name())
            if new_gen < old_gen or (new_gen == old_gen and new_pid != old_pid):
                log(f"Pad {pad.get_name()} ignoriert (weitere {kind}-Spur, ausgegeben wird {peer.get_name()})")
                return
            peer.unlink(sink)
        res = pad.link(sink)
        log(f"Pad {pad.get_name()} -> {tgt.get_name()}: {res.value_nick}")

    vdec = pipe.get_by_name("vdec")
    vsel = pipe.get_by_name("vsel")

    def on_video_pad(_el, pad):
        caps = pad.get_current_caps() or pad.query_caps(None)
        st = caps.get_structure(0) if caps is not None and caps.get_size() else None
        mode = st.get_string("interlace-mode") if st is not None else None
        fps = 0.0
        if st is not None:
            ok, num, den = st.get_fraction("framerate")
            if ok and den:
                fps = num / den
        way = "A"
        if rates and (mode or "progressive") == "progressive" and pipe.get_by_name("thB1") is not None:
            way = "B1" if fps > 40 else "B2"
        head = pipe.get_by_name("th" + way)
        hsink = head.get_static_pad("sink")
        old = hsink.get_peer()
        if old is not None:
            old.unlink(hsink)
        res = pad.link(hsink)
        last = pipe.get_by_name("tlast" + way)
        selpad = last.get_static_pad("src").get_peer()
        if selpad is not None:
            vsel.set_property("active-pad", selpad)
        log(f"Video {mode or '?'} {fps:.2f} fps -> Weg {way}: {res.value_nick}")

    vdec.connect("pad-added", on_video_pad)

    demux.connect("pad-added", on_pad_added)
    demux.connect("pad-removed", lambda _e, pad: log(f"Pad entfernt: {pad.get_name()}"))
    return pipe


def struct_numbers(st) -> dict:
    """Zahlenfelder einer GstStructure als dict (Bindestriche werden zu Unterstrichen)."""
    out = {}
    if st is None:
        return out
    for i in range(st.n_fields()):
        name = st.nth_field_name(i)
        try:
            val = st.get_value(name)
        except Exception:
            continue
        if isinstance(val, (int, float)) and not isinstance(val, bool):
            out[name.replace("-", "_")] = val
    return out


def write_stats(a) -> bool:
    """Schreibt jede Sekunde SRT-Zahlen, Puffer-Fuellstaende und Ausgabe-Zaehler als JSON (atomar)."""
    pipe = state.get("pipe")
    if pipe is None or not a.stats_file:
        return True
    data = {"time": time.time(), "srt": {}, "queues": {}, "sinks": {}}
    try:
        data["srt"] = struct_numbers(pipe.get_by_name("src").get_property("stats"))
    except Exception as e:
        data["srt_error"] = str(e)
    for name in ("vq", "aq"):
        q = pipe.get_by_name(name)
        if q is not None:
            try:
                data["queues"][name] = {"ms": q.get_property("current-level-time") // 1000000,
                                        "buffers": q.get_property("current-level-buffers")}
            except Exception:
                pass
    for name in ("vsink", "asink"):
        sink = pipe.get_by_name(name)
        if sink is not None:
            try:
                data["sinks"][name] = struct_numbers(sink.get_property("stats"))
            except Exception:
                pass
    tmp = a.stats_file + ".tmp"
    try:
        with open(tmp, "w") as f:
            json.dump(data, f)
        os.replace(tmp, a.stats_file)
    except OSError as e:
        log(f"Statistik nicht schreibbar: {e}")
    return True


def run_once(a) -> int:
    """Baut die Pipeline auf und laeuft bis Fehler, EOS oder Stop. 0 = ok/Stop, 1 = Fehler."""
    pipe = build(a)
    state["pipe"] = pipe
    stats_timer = GLib.timeout_add_seconds(1, write_stats, a)
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
        state["pipe"] = None
        pipe.set_state(Gst.State.NULL)  # schliesst die SRT-Verbindung sauber, gibt die Karte frei
        bus.remove_signal_watch()
        state["loop"] = None
    return code["value"]


def pause(seconds: float) -> None:
    """Wartet, bleibt dabei aber fuer SIGTERM/SIGINT ansprechbar."""
    loop = GLib.MainLoop()
    state["loop"] = loop
    GLib.timeout_add(int(seconds * 1000), loop.quit)
    loop.run()
    state["loop"] = None


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--uri", required=True)
    ap.add_argument("--latency", type=int, default=120)
    ap.add_argument("--device", type=int, required=True)
    ap.add_argument("--mode", required=True)
    ap.add_argument("--profile", default="default")
    ap.add_argument("--audio-channels", type=int, default=2)
    ap.add_argument("--passphrase")
    ap.add_argument("--pbkeylen", type=int, default=0)
    ap.add_argument("--streamid")
    ap.add_argument("--stats-file", help="JSON-Datei fuer SRT-Zahlen und Ausgabe-Zaehler (jede Sekunde neu)")
    ap.add_argument("--tap-port", type=int, default=0, help="UDP-Port auf 127.0.0.1 fuer den Stromabgriff (Statistik)")
    ap.add_argument("--null-sinks", action="store_true", help="fakesink statt DeckLink (nur Tests ohne Karte)")
    a = ap.parse_args()
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
