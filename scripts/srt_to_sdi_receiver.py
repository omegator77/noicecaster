#!/usr/bin/env python3
"""SRT- oder RTMP-Empfang -> DeckLink-Ausgabe (Video + Audio) mit dynamischer Pad-Behandlung.

Wird von PipelineManager::buildReceiveCommand() gestartet. tsdemux legt seine Streams beim
Verbindungsaufbau neu an (audio_0_0042 -> audio_1_0042, video_1_0041); jedes neue Pad wird hier
selbst mit dem Ausgabezweig verbunden. Der Codec wird per decodebin erkannt (H.264/H.265/MPEG-2,
Audio u. a. AAC, AC3, SMPTE 302M).

Selbstueberwachung: Bricht die Pipeline mit einem Fehler ab (z. B. der Videoausgang meldet einen
Stream-Fehler, wenn der Sender neu startet und die Zeitstempel zurueckspringen), wird sie nach
kurzer Pause neu aufgebaut. SIGTERM/SIGINT beenden das Programm sofort und schliessen die
SRT-Verbindung sauber.

RTMP (--rtmp-uri): rtmp2src holt den Strom von einem RTMP-Server ab (Client), flvdemux liefert H.264 und AAC. Der
Rest (Ausgabewege, Ton, Selbstueberwachung) ist derselbe wie bei SRT. Ein Abbruch oder Ende des Stroms fuehrt zum
Neuaufbau nach 2 s, so holt sich der Empfaenger den Strom nach einer Unterbrechung selbst zurueck. Kein Stromabgriff:
die Statistik kommt aus rtmp2src.stats.
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
    # Zusaetzlich: MPEG-2-Decoder melden den Zeilensprung als "mixed" (je Bild); der DeckLink-Ausgang im
    # Halbbild-Modus nimmt nur "interleaved" an, deshalb wird das Caps-Event in on_video_pad umgeschrieben.
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
    used = a.audio_used if 0 < a.audio_used <= a.audio_channels else a.audio_channels
    if a.audio_channels > 2:
        mix = "audiomixmatrix mode=first-channels channel-mask=0 ! "
        if used < a.audio_channels:
            # Kanaele ueber der gewaehlten Zahl stumm schalten (Diagonalmatrix mit Einsen nur fuer die ersten `used`)
            d = a.audio_channels
            rows = ["<" + ",".join("(double)" + ("1.0" if c == r and r < used else "0.0") for c in range(d)) + ">" for r in range(d)]
            mix += (f"audiomixmatrix in-channels={d} out-channels={d} mode=manual channel-mask=0 "
                    f"matrix=\"<{','.join(rows)}>\" ! ")
        caps = f"audio/x-raw,format=S16LE,rate=48000,channels={a.audio_channels},channel-mask=(bitmask)0"
    else:
        mix = ""
        caps = f"audio/x-raw,format=S16LE,rate=48000,channels={a.audio_channels}"
    # Mehrere Stereo-Spuren (je eigene PID, z. B. 66 = Kanal 1+2, 67 = Kanal 3+4): sie werden nach PID geordnet und
    # in build_multi_audio() zu EINEM Mehrkanal-Ton zusammengesetzt. Der Weg ueber "aq" ist die Einzelspur
    # (wie bisher), beide muenden im input-selector asel vor dem Ausgang.
    out_caps = caps
    audio = (
        "queue name=aq max-size-time=1000000000 ! decodebin ! audioconvert ! audioresample ! "
        f"{mix}{caps} ! asel.  "
        "input-selector name=asel ! "
        + ("level name=lvl interval=1000000000 post-messages=true ! " if a.audio_levels else "")
        + f"{asink}"
    )
    # Abgriff fuer die Statistik: Kopie des empfangenen Transportstroms auf 127.0.0.1 (leckere Queue, damit ein
    # fehlender oder langsamer Leser den Empfang nie bremst; tsparse buendelt 7 TS-Pakete je Datagramm).
    # Der Empfangsweg haengt direkt am ersten tee-Zweig und bleibt damit so wie vorher.
    if a.rtmp_uri:
        # Grosse Queues (10 s) hinter flvdemux: Der Ton kommt vor dem ersten Keyframe. Ist aq (1 s) voll, blockiert der Demuxer,
        # und das Video, das den Ausgang erst in Gang bringt, kommt nie an (Vorlauf-Stillstand, 22 kB und dann nichts).
        video = video.replace("queue name=vq !", "queue name=vq max-size-buffers=0 max-size-bytes=0 max-size-time=10000000000 !")
        audio = audio.replace("queue name=aq max-size-time=1000000000 !", "queue name=aq max-size-buffers=0 max-size-bytes=0 max-size-time=10000000000 !")
        # flvdemux legt seine Pads "video" und "audio" beim Verbindungsaufbau an (on_pad_added verbindet sie mit
        # vq/aq); die Queues dahinter verhindern, dass die beiden Ausgaenge beim Anlaufen aufeinander warten.
        pipe = Gst.parse_launch(f"rtmp2src name=src ! flvdemux name=demux  {video}  {audio}")
        src = pipe.get_by_name("src")
        src.set_property("location", a.rtmp_uri)
        src.set_property("timeout", 5)
        # Bleibt der Strom aus (Server tot, Sender weg), nach 10 s abbrechen: die Selbstueberwachung baut dann neu auf
        src.set_property("idle-timeout", 10)
    else:
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
    asel = pipe.get_by_name("asel")
    ast = {"gen": -1, "pads": {}, "timer": 0, "multi": False}

    def request_pad(el, tmpl):
        return el.request_pad_simple(tmpl) if hasattr(el, "request_pad_simple") else el.get_request_pad(tmpl)

    def build_multi_audio(items):
        """items = [(pid, demux_pad)] nach PID sortiert: Spur k belegt die Ausgangskanaele 2k+1 und 2k+2."""
        n = a.audio_channels
        # Mischer und Ausgangsformat direkt in der Pipeline (Pads in einer Bin waeren "falsche Hierarchie")
        amix = Gst.ElementFactory.make("audiomixer", "amix")
        conv = Gst.ElementFactory.make("audioconvert", "amixconv")
        cf = Gst.ElementFactory.make("capsfilter", "amixcaps")
        cf.set_property("caps", Gst.Caps.from_string(out_caps))
        for el in (amix, conv, cf):
            pipe.add(el)
        amix.link(conv)
        conv.link(cf)
        sel_pad = request_pad(asel, "sink_%u")
        cf.get_static_pad("src").link(sel_pad)
        branches = []
        for k, (pid, dpad) in enumerate(items):
            rows = []
            for out in range(n):
                row = [0.0, 0.0]
                if out == 2 * k:
                    row[0] = 1.0
                elif out == 2 * k + 1:
                    row[1] = 1.0
                rows.append("<" + ",".join(f"(double){v}" for v in row) + ">")
            matrix = "<" + ",".join(rows) + ">"
            # Ghost-Pads von Hand: die automatischen haengen wegen des spaet verbundenen decodebin am falschen Element
            br = Gst.parse_bin_from_description(
                "queue name=bq max-size-time=1000000000 ! decodebin ! audioconvert ! audioresample ! "
                "audio/x-raw,format=F32LE,rate=48000,channels=2 ! "
                f"audiomixmatrix in-channels=2 out-channels={n} mode=manual channel-mask=0 matrix=\"{matrix}\" ! "
                f"capsfilter name=bout caps=\"audio/x-raw,format=F32LE,rate=48000,channels={n},channel-mask=(bitmask)0\"", False)
            br.add_pad(Gst.GhostPad.new("sink", br.get_by_name("bq").get_static_pad("sink")))
            br.add_pad(Gst.GhostPad.new("src", br.get_by_name("bout").get_static_pad("src")))
            pipe.add(br)
            br.get_static_pad("src").link(request_pad(amix, "sink_%u"))
            branches.append((pid, dpad, br))
        for el in [amix, conv, cf] + [b for _p, _d, b in branches]:
            el.sync_state_with_parent()
        asel.set_property("active-pad", sel_pad)
        for k, (pid, dpad, br) in enumerate(branches):
            res = dpad.link(br.get_static_pad("sink"))
            log(f"Pad {dpad.get_name()} -> Mehrspur {2 * k + 1}+{2 * k + 2}: {res.value_nick}")

    def finalize_audio():
        ast["timer"] = 0
        pads = [ast["pads"][k] for k in sorted(ast["pads"])]
        used = a.audio_used if 0 < a.audio_used <= a.audio_channels else a.audio_channels
        if len(pads) > 1 and used > 2 and a.audio_channels > 2:
            keep = pads[: used // 2]
            for extra in pads[len(keep):]:
                log(f"Pad {extra.get_name()} ignoriert (mehr Spuren als Ausgangskanaele)")
            ast["multi"] = True
            # die zuerst gemeldete Spur ist schon auf den Einzelspur-Weg gelinkt: dort loesen
            sink = targets["audio"].get_static_pad("sink")
            peer = sink.get_peer()
            if peer is not None:
                peer.unlink(sink)
            build_multi_audio([(pad_info(pd.get_name())[1], pd) for pd in keep])
        else:
            for extra in pads[1:]:
                log(f"Pad {extra.get_name()} ignoriert (weitere audio-Spur, ausgegeben wird {pads[0].get_name()})")
        return False

    def audio_pad_added(pad):
        gen, pid = pad_info(pad.get_name())
        if gen < ast["gen"]:
            log(f"Pad {pad.get_name()} ignoriert (alte Generation)")
            return
        if gen > ast["gen"]:
            if ast["multi"]:
                # Mehrspur-Aufbau ist fest verdrahtet: bei neuem Strom die ganze Pipeline neu aufbauen
                log("Neuer Strom bei Mehrspur-Ton: Pipeline wird neu aufgebaut")
                state["fail"] = True
                if state["loop"] is not None:
                    GLib.idle_add(state["loop"].quit)
                return
            ast["gen"] = gen
            ast["pads"] = {}
            # erste Spur eines Stroms sofort auf den Einzelspur-Weg legen (tsdemux braucht ein Ziel, sonst
            # meldet es "not-linked"); gibt es mehr Spuren, wird in finalize_audio auf den Mehrspur-Weg umgestellt
            tgt = targets["audio"]
            sink = tgt.get_static_pad("sink")
            peer = sink.get_peer()
            if peer is not None:
                peer.unlink(sink)
            res = pad.link(sink)
            sel = asel.get_static_pad("sink_0")
            if sel is not None:
                asel.set_property("active-pad", sel)
            log(f"Pad {pad.get_name()} -> {tgt.get_name()}: {res.value_nick}")
        ast["pads"][pid] = pad
        if ast["timer"]:
            GLib.source_remove(ast["timer"])
        # Die Spuren eines Stroms melden sich innerhalb von Millisekunden; kurz sammeln, dann entscheiden
        ast["timer"] = GLib.timeout_add(250, finalize_audio)

    def on_pad_added(_el, pad):
        kind = pad.get_name().split("_", 1)[0]
        tgt = targets.get(kind)
        if tgt is None:
            log(f"Pad {pad.get_name()} ignoriert")
            return
        if kind == "audio":
            audio_pad_added(pad)
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
        elif rates and mode == "mixed":
            def mixed_to_interleaved(src_pad, info):
                ev = info.get_event()
                try:
                    is_caps = ev is not None and ev.type == Gst.EventType.CAPS
                except ValueError:  # Ereignistyp, den die Python-Enum nicht kennt
                    return Gst.PadProbeReturn.OK
                if is_caps:
                    caps = ev.parse_caps()
                    st0 = caps.get_structure(0)
                    if st0 is not None and st0.get_string("interlace-mode") == "mixed":
                        new_st = st0.copy()
                        new_st.set_value("interlace-mode", "interleaved")
                        new_st.set_value("field-order", "top-field-first")
                        new = Gst.Caps.new_empty()
                        new.append_structure(new_st)
                        src_pad.push_event(Gst.Event.new_caps(new))
                        return Gst.PadProbeReturn.DROP
                return Gst.PadProbeReturn.OK

            pad.add_probe(Gst.PadProbeType.EVENT_DOWNSTREAM, mixed_to_interleaved)
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
    if a.rtmp_uri:
        data["protocol"] = "rtmp"
    try:
        key = "rtmp" if a.rtmp_uri else "srt"
        data[key] = struct_numbers(pipe.get_by_name("src").get_property("stats"))
    except Exception as e:
        data[("rtmp" if a.rtmp_uri else "srt") + "_error"] = str(e)
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
        elif msg.type == Gst.MessageType.ELEMENT and a.audio_levels:
            st = msg.get_structure()
            if st is not None and st.get_name() == "level":
                try:
                    log("PEGEL rms dB: " + " ".join(str(round(v)) for v in st.get_value("rms")))
                except Exception:
                    pass

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
    return 1 if state.pop("fail", False) else code["value"]


def pause(seconds: float) -> None:
    """Wartet, bleibt dabei aber fuer SIGTERM/SIGINT ansprechbar."""
    loop = GLib.MainLoop()
    state["loop"] = loop
    GLib.timeout_add(int(seconds * 1000), loop.quit)
    loop.run()
    state["loop"] = None


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--uri", help="SRT-URI (srt://...)")
    ap.add_argument("--rtmp-uri", help="RTMP-/RTMPS-URL des abzurufenden Stroms (rtmp://Host/Anwendung/Name); ersetzt --uri")
    ap.add_argument("--latency", type=int, default=120)
    ap.add_argument("--device", type=int, required=True)
    ap.add_argument("--mode", required=True)
    ap.add_argument("--profile", default="default")
    ap.add_argument("--audio-channels", type=int, default=2, help="Kanaele, die die DeckLink-Karte oeffnet (2, 8 oder 16)")
    ap.add_argument("--audio-used", type=int, default=0, help="davon genutzte Kanaele (gerade, <= audio-channels), der Rest bleibt still")
    ap.add_argument("--passphrase")
    ap.add_argument("--pbkeylen", type=int, default=0)
    ap.add_argument("--streamid")
    ap.add_argument("--stats-file", help="JSON-Datei fuer SRT-Zahlen und Ausgabe-Zaehler (jede Sekunde neu)")
    ap.add_argument("--tap-port", type=int, default=0, help="UDP-Port auf 127.0.0.1 fuer den Stromabgriff (Statistik)")
    ap.add_argument("--null-sinks", action="store_true", help="fakesink statt DeckLink (nur Tests ohne Karte)")
    ap.add_argument("--audio-levels", action="store_true", help="Pegel je Ausgangskanal jede Sekunde ins Log (Tests)")
    a = ap.parse_args()
    if not a.uri and not a.rtmp_uri:
        ap.error("--uri oder --rtmp-uri ist erforderlich")
    Gst.init(None)

    def on_signal(*_):
        state["stopping"] = True
        if state["loop"] is not None:
            state["loop"].quit()
        return True

    GLib.unix_signal_add(GLib.PRIORITY_HIGH, signal.SIGTERM, on_signal)
    GLib.unix_signal_add(GLib.PRIORITY_HIGH, signal.SIGINT, on_signal)

    try:
        fails = 0
        while not state["stopping"]:
            rc = run_once(a)
            if state["stopping"]:
                break
            # RTMP: bei wiederholten Fehlern (Server/Strom nicht da) bis 5 s warten, sonst waechst das Log
            fails = fails + 1 if (a.rtmp_uri and rc != 0) else 0
            wait = min(5, 2 * fails) if fails > 1 else 2
            log(f"Pipeline beendet (Code {rc}), Neuaufbau in {wait} s")
            pause(wait)
    finally:
        if a.stats_file:
            try:
                os.remove(a.stats_file)
            except OSError:
                pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
