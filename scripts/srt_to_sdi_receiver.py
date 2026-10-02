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
import signal
import sys

import gi

gi.require_version("Gst", "1.0")
from gi.repository import GLib, Gst  # noqa: E402

state = {"stopping": False, "loop": None}


def log(msg: str) -> None:
    print(msg, flush=True)


def build(a):
    video = (
        "queue name=vq ! decodebin ! videoconvert ! videoscale ! videorate ! "
        f"decklinkvideosink device-number={a.device} mode={a.mode} profile={a.profile}"
    )
    audio = (
        "queue name=aq max-size-time=1000000000 ! decodebin ! audioconvert ! audioresample ! "
        f"audio/x-raw,format=S16LE,rate=48000,channels={a.audio_channels} ! "
        f"decklinkaudiosink device-number={a.device}"
    )
    pipe = Gst.parse_launch(f"srtsrc name=src ! tsdemux name=demux  {video}  {audio}")

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
        if peer is not None:  # altes Pad derselben Art (Stream wurde neu angelegt)
            peer.unlink(sink)
        res = pad.link(sink)
        log(f"Pad {pad.get_name()} -> {tgt.get_name()}: {res.value_nick}")

    demux.connect("pad-added", on_pad_added)
    demux.connect("pad-removed", lambda _e, pad: log(f"Pad entfernt: {pad.get_name()}"))
    return pipe


def run_once(a) -> int:
    """Baut die Pipeline auf und laeuft bis Fehler, EOS oder Stop. 0 = ok/Stop, 1 = Fehler."""
    pipe = build(a)
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
    a = ap.parse_args()
    Gst.init(None)

    def on_signal(*_):
        state["stopping"] = True
        if state["loop"] is not None:
            state["loop"].quit()
        return True

    GLib.unix_signal_add(GLib.PRIORITY_HIGH, signal.SIGTERM, on_signal)
    GLib.unix_signal_add(GLib.PRIORITY_HIGH, signal.SIGINT, on_signal)

    while not state["stopping"]:
        rc = run_once(a)
        if state["stopping"]:
            break
        log(f"Pipeline beendet (Code {rc}), Neuaufbau in 2 s")
        pause(2)
    return 0


if __name__ == "__main__":
    sys.exit(main())
