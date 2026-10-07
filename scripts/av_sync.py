#!/usr/bin/env python3
"""Misst den Ton/Bild-Versatz eines Streams anhand eines Blitz-und-Piep-Signals.

Der Strom wird (vom UDP-Abgriff oder aus einer Datei) dekodiert; aus den echten
Zeitstempeln werden die Zeitpunkte der Blitze (Helligkeitssprung im Bild) und der
Pieptoene (Pegelsprung im Ton) bestimmt und gegeneinander gerechnet.

  Ergebnis > 0: Ton kommt SPAETER als das Bild, < 0: Ton kommt FRUEHER.

Aufloesung: Bild 1 Frame (z. B. 40 ms), Ton 10 ms. Gemessen wird der Strom, also das,
was ein Empfaenger sieht, nicht das Quellsignal.
"""
import argparse
import array
import json
import math
import os
import socket
import statistics
import sys
import tempfile
import time

import gi

gi.require_version('Gst', '1.0')
from gi.repository import GLib, Gst  # noqa: E402

BLOCK_MS = 10.0
MATCH_WINDOW_MS = 500.0
DEBOUNCE_MS = 300.0


def capture(port, seconds):
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
    try:
        sock.bind(('127.0.0.1', port))
    except OSError as e:
        return None, 'Port %d nicht verfuegbar: %s' % (port, e)
    sock.settimeout(1.0)
    fd, path = tempfile.mkstemp(suffix='.ts', prefix='avsync_')
    got = 0
    start = time.time()
    with os.fdopen(fd, 'wb') as f:
        while time.time() - start < seconds:
            try:
                d = sock.recv(70000)
            except socket.timeout:
                if not got and time.time() - start > 1.5:
                    break
                continue
            f.write(d)
            got += len(d)
    sock.close()
    if not got:
        os.unlink(path)
        return None, 'keine Daten am Abgriff'
    return path, None


def decode(path, timeout=60):
    """Liefert (Liste (t_ms, Helligkeit 0..255), Liste (t_ms, Pegel linear 0..1))."""
    Gst.init(None)
    pipeline = Gst.Pipeline.new('avsync')
    src = Gst.ElementFactory.make('filesrc', None)
    src.set_property('location', path)
    demux = Gst.ElementFactory.make('tsdemux', None)
    # queue vor tsdemux erzwingt den Push-Modus: nur so vergibt tsdemux bei einem mitten im
    # Strom begonnenen Mitschnitt zuverlaessig Zeitstempel (Pull-Modus: "No groups")
    q = Gst.ElementFactory.make('queue', None)
    pipeline.add(src)
    pipeline.add(q)
    pipeline.add(demux)
    src.link(q)
    q.link(demux)

    video, audio = [], []

    def on_video(sink):
        sample = sink.emit('pull-sample')
        buf = sample.get_buffer()
        ok, info = buf.map(Gst.MapFlags.READ)
        if ok and buf.pts != Gst.CLOCK_TIME_NONE:
            data = bytes(info.data)
            video.append((buf.pts / 1e6, sum(data) / max(1, len(data))))
        if ok:
            buf.unmap(info)
        return Gst.FlowReturn.OK

    def on_audio(sink):
        sample = sink.emit('pull-sample')
        buf = sample.get_buffer()
        ok, info = buf.map(Gst.MapFlags.READ)
        if ok and buf.pts != Gst.CLOCK_TIME_NONE:
            samples = array.array('h')
            samples.frombytes(bytes(info.data)[: len(info.data) // 2 * 2])
            n = int(48000 * BLOCK_MS / 1000)
            t0 = buf.pts / 1e6
            for k in range(0, len(samples) - n + 1, n):
                block = samples[k:k + n]
                rms = math.sqrt(sum(x * x for x in block) / n) / 32768.0
                audio.append((t0 + k / 48.0, rms))
        if ok:
            buf.unmap(info)
        return Gst.FlowReturn.OK

    # async=false an beiden Senken: sonst wartet die Pipeline, bis BEIDE Zweige ein erstes Bild/Tonpaket
    # haben; der Ton-Zweig blockiert dann den Demuxer, bevor das Video den ersten Keyframe sieht (Deadlock)
    def pad_added(_demux, pad):
        name = pad.get_name()
        if name.startswith('video'):
            desc = ('queue ! h264parse ! avdec_h264 ! videoconvert ! videoscale ! '
                    'video/x-raw,format=GRAY8,width=64,height=36 ! appsink name=vsink emit-signals=true sync=false async=false')
            cb, sink_name = on_video, 'vsink'
        elif name.startswith('audio'):
            caps = pad.get_current_caps() or pad.query_caps(None)
            st = caps.get_structure(0)
            kind = st.get_name()
            mpeg = st.get_int('mpegversion')[1] if st.has_field('mpegversion') else 0
            if kind == 'audio/mpeg' and mpeg == 4:
                dec = 'aacparse ! avdec_aac'
            elif kind == 'audio/mpeg' and mpeg in (1, 2):
                dec = 'mpegaudioparse ! avdec_mp2float'
            elif kind in ('audio/x-ac3', 'audio/x-eac3'):
                dec = 'ac3parse ! avdec_ac3'
            else:
                return
            desc = ('queue ! ' + dec + ' ! audioconvert ! audioresample ! '
                    'audio/x-raw,format=S16LE,channels=1,rate=48000 ! appsink name=asink emit-signals=true sync=false async=false')
            cb, sink_name = on_audio, 'asink'
        else:
            return
        b = Gst.parse_bin_from_description(desc, True)
        pipeline.add(b)
        b.sync_state_with_parent()
        pad.link(b.get_static_pad('sink'))
        b.get_by_name(sink_name).connect('new-sample', cb)

    demux.connect('pad-added', pad_added)
    loop = GLib.MainLoop()
    bus = pipeline.get_bus()
    bus.add_signal_watch()
    err = []

    def on_msg(_bus, msg):
        if msg.type == Gst.MessageType.EOS:
            loop.quit()
        elif msg.type == Gst.MessageType.ERROR:
            err.append(msg.parse_error()[0].message)
            loop.quit()

    bus.connect('message', on_msg)
    GLib.timeout_add_seconds(timeout, loop.quit)
    pipeline.set_state(Gst.State.PLAYING)
    loop.run()
    pipeline.set_state(Gst.State.NULL)
    return video, audio, (err[0] if err else None)


def video_flashes(video):
    """Zeitpunkte, an denen das Bild vom Normalzustand in den Blitz springt."""
    if len(video) < 10:
        return [], 'zu wenige Bilder dekodiert'
    vals = [v for _, v in video]
    med, lo, hi = statistics.median(vals), min(vals), max(vals)
    if hi - lo < 25:
        return [], 'kein Helligkeitssprung im Bild (kein Blitz erkannt)'
    bright = (hi - med) >= (med - lo)
    thr = med + (hi - med) / 2 if bright else med - (med - lo) / 2
    out, prev = [], False
    for t, v in video:
        on = v > thr if bright else v < thr
        if on and not prev and (not out or t - out[-1] >= DEBOUNCE_MS):
            out.append(t)
        prev = on
    return out, None


def audio_beeps(audio):
    """Zeitpunkte, an denen der Pegel aus der Stille in den Piepton springt."""
    if len(audio) < 50:
        return [], 'zu wenig Ton dekodiert'
    vals = [v for _, v in audio]
    hi = max(vals)
    if hi < 0.003:
        return [], 'kein Ton erkannt'
    thr = hi * 0.5
    out, quiet = [], 0
    for t, v in audio:
        if v > thr:
            if quiet >= 8 and (not out or t - out[-1] >= DEBOUNCE_MS):   # mindestens 80 ms Stille davor
                out.append(t)
            quiet = 0
        else:
            quiet += 1
    return out, (None if out else 'kein Piepton erkannt (Pegel springt nicht aus der Stille)')


def measure(video, audio):
    flashes, ferr = video_flashes(video)
    beeps, berr = audio_beeps(audio)
    res = {'ok': False, 'frames': len(video), 'flashes': len(flashes), 'beeps': len(beeps)}
    if video:
        vals = [v for _, v in video]
        res['luma_range'] = [round(min(vals), 1), round(max(vals), 1)]
    if ferr or berr:
        res['error'] = '; '.join(e for e in (ferr, berr) if e) + ' (im Messfenster von rund 10 s)'
        return res
    offsets = []
    for f in flashes:
        near = min(beeps, key=lambda b: abs(b - f))
        back = min(flashes, key=lambda x: abs(x - near))
        # nur gegenseitig naechste Nachbarn: ein Blitz und ein Piepton gehoeren eindeutig zusammen
        if back == f and abs(near - f) <= MATCH_WINDOW_MS:
            offsets.append(near - f)
    if not offsets:
        res['error'] = 'Blitze und Pieptoene liegen nicht zusammen (Abstand > %d ms)' % MATCH_WINDOW_MS
        return res
    if len(offsets) < 2:
        res['error'] = ('nur %d zusammengehoeriges Blitz/Piep-Paar gefunden (%d Blitze, %d Pieptoene) - '
                        'fuer eine verlaessliche Messung werden mindestens 2 gebraucht' % (len(offsets), len(flashes), len(beeps)))
        return res
    res.update({'ok': True, 'pairs': len(offsets), 'median_ms': round(statistics.median(offsets), 1),
                'min_ms': round(min(offsets), 1), 'max_ms': round(max(offsets), 1),
                'offsets_ms': [round(x, 1) for x in offsets]})
    return res


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--port', type=int)
    ap.add_argument('--seconds', type=float, default=8.0)
    ap.add_argument('--file')
    args = ap.parse_args()
    path, tmp = args.file, False
    if not path:
        if not args.port:
            print(json.dumps({'ok': False, 'error': 'Port oder Datei angeben'}))
            return 1
        path, err = capture(args.port, args.seconds)
        if err:
            print(json.dumps({'ok': False, 'error': err}))
            return 0
        tmp = True
    try:
        video, audio, err = decode(path)
        if err and not video:
            print(json.dumps({'ok': False, 'error': 'Dekodierfehler: ' + err}))
            return 0
        print(json.dumps(measure(video, audio)))
    finally:
        if tmp:
            os.unlink(path)
    return 0


if __name__ == '__main__':
    sys.exit(main())
