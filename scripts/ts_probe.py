#!/usr/bin/env python3
"""Wertet einen MPEG-Transportstrom aus und gibt die Kennzahlen als JSON aus.

Quelle: UDP-Abgriff auf 127.0.0.1 hinter dem Muxer der Sender-Pipelines
(--port N, --seconds S) oder eine Datei (--file x.ts, zum Testen).

Gemessen werden: PAT/PMT (PIDs, Stream-Typen, PMT-Version), Bitrate je PID,
Video (Profil, Level, Aufloesung, Bildrate, GOP), Audio (Codec, Abtastrate,
Kanaele, Frame-Abstand), Timing (Ton/Bild-Versatz, Abstands-Regelmaessigkeit,
PCR-Abstand) und Continuity-Fehler.
"""
import argparse
import json
import socket
import statistics
import sys
import time

STREAM_TYPES = {
    0x01: 'MPEG-1 Video', 0x02: 'MPEG-2 Video', 0x03: 'MPEG-1 Audio (MP2/MP3)', 0x04: 'MPEG-2 Audio',
    0x06: 'PES privat (z. B. SMPTE 302M)', 0x0f: 'AAC (ADTS)', 0x11: 'AAC (LATM)', 0x1b: 'H.264',
    0x24: 'H.265', 0x81: 'AC-3', 0x87: 'E-AC-3',
}
VIDEO_TYPES = {0x01, 0x02, 0x1b, 0x24}
AUDIO_TYPES = {0x03, 0x04, 0x06, 0x0f, 0x11, 0x81, 0x87}
AAC_RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350]
MPA_RATES = {3: [44100, 48000, 32000], 2: [22050, 24000, 16000], 0: [11025, 12000, 8000]}
MPA_BITRATES = {2: [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
                1: [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]}
PROFILES = {66: 'Baseline', 77: 'Main', 88: 'Extended', 100: 'High', 110: 'High 10', 122: 'High 4:2:2', 244: 'High 4:4:4'}
MAX_VIDEO_BYTES = 600000


def read_packets(args):
    """Liefert eine Liste von 188-Byte-TS-Paketen."""
    if args.file:
        data = open(args.file, 'rb').read()
        return [data[i:i + 188] for i in range(0, len(data) - 187, 188)], None
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
    try:
        sock.bind(('127.0.0.1', args.port))
    except OSError as e:
        return None, 'Port %d nicht verfuegbar: %s' % (args.port, e)
    sock.settimeout(1.0)
    packets = []
    start = time.time()
    while time.time() - start < args.seconds:
        try:
            d, _ = sock.recvfrom(70000)
        except socket.timeout:
            if not packets and time.time() - start > 1.0:
                break
            continue
        for i in range(0, len(d) - 187, 188):
            packets.append(d[i:i + 188])
    sock.close()
    return packets, None


def pts_from_pes(payload):
    if len(payload) < 14 or payload[0:3] != b'\x00\x00\x01' or not payload[7] & 0x80:
        return None
    x = payload[9:14]
    return ((x[0] >> 1) & 7) << 30 | x[1] << 22 | (x[2] >> 1) << 15 | x[3] << 7 | (x[4] >> 1)


def unescape(b):
    out = bytearray()
    i = 0
    while i < len(b):
        if i + 2 < len(b) and b[i] == 0 and b[i + 1] == 0 and b[i + 2] == 3:
            out += b[i:i + 2]
            i += 3
        else:
            out.append(b[i])
            i += 1
    return bytes(out)


class Bits:
    def __init__(self, data):
        self.d, self.p = data, 0

    def u(self, n):
        v = 0
        for _ in range(n):
            byte = self.d[self.p >> 3] if (self.p >> 3) < len(self.d) else 0
            v = (v << 1) | ((byte >> (7 - (self.p & 7))) & 1)
            self.p += 1
        return v

    def ue(self):
        z = 0
        while self.u(1) == 0 and z < 32:
            z += 1
        return (1 << z) - 1 + self.u(z) if z else 0

    def se(self):
        k = self.ue()
        return (k + 1) // 2 if k & 1 else -(k // 2)


def parse_sps(nal):
    """H.264-SPS: Profil, Level, Aufloesung, progressiv/interlaced, optional Bildrate aus VUI."""
    b = Bits(unescape(nal[1:]))
    profile = b.u(8)
    b.u(8)
    level = b.u(8)
    b.ue()
    chroma = 1
    if profile in (100, 110, 122, 244, 44, 83, 86, 118, 128):
        chroma = b.ue()
        if chroma == 3:
            b.u(1)
        b.ue(); b.ue(); b.u(1)
        if b.u(1):
            for i in range(8 if chroma != 3 else 12):
                if b.u(1):
                    last = 8
                    for _ in range(16 if i < 6 else 64):
                        last = (last + b.se()) % 256 if last else 0
    b.ue()
    poc = b.ue()
    if poc == 0:
        b.ue()
    elif poc == 1:
        b.u(1); b.se(); b.se()
        for _ in range(b.ue()):
            b.se()
    b.ue(); b.u(1)
    w = (b.ue() + 1) * 16
    hmap = b.ue() + 1
    frame_mbs_only = b.u(1)
    if not frame_mbs_only:
        b.u(1)
    b.u(1)
    h = (2 - frame_mbs_only) * hmap * 16
    if b.u(1):
        l, r, t, bt = b.ue(), b.ue(), b.ue(), b.ue()
        cx = 2 if chroma in (1, 2) else 1
        cy = (2 if chroma == 1 else 1) * (2 - frame_mbs_only)
        w -= cx * (l + r)
        h -= cy * (t + bt)
    fps = None
    if b.u(1):  # vui
        if b.u(1):
            if b.u(8) == 255:
                b.u(16); b.u(16)
        if b.u(1):
            b.u(1)
        if b.u(1):
            b.u(4)
            if b.u(1):
                b.u(24)
        if b.u(1):
            b.ue(); b.ue()
        if b.u(1):
            units, scale = b.u(32), b.u(32)
            if units:
                fps = scale / (2.0 * units)
    return {'profile': PROFILES.get(profile, str(profile)), 'level': '%.1f' % (level / 10.0),
            'width': w, 'height': h, 'interlaced': not frame_mbs_only, 'sps_fps': fps}


def is_keyframe(head):
    """IDR-Slice (NAL-Typ 5) oder SPS (7) am Anfang des Bildes."""
    i = 0
    while True:
        i = head.find(b'\x00\x00\x01', i)
        if i < 0 or i + 3 >= len(head):
            return False
        if head[i + 3] & 0x1f in (5, 7):
            return True
        i += 3


MPEG2_FPS = {1: 24000 / 1001, 2: 24.0, 3: 25.0, 4: 30000 / 1001, 5: 30.0, 6: 50.0, 7: 60000 / 1001, 8: 60.0}
MPEG2_PROFILES = {5: 'Simple', 4: 'Main', 3: 'SNR', 2: 'Spatial', 1: 'High'}
MPEG2_LEVELS = {10: 'Low', 8: 'Main', 6: 'High-1440', 4: 'High'}


def is_keyframe_mpeg2(head):
    """Sequenzkopf (B3), GOP-Kopf (B8) oder I-Bild (picture_coding_type 1) am Anfang des Bildes."""
    i = 0
    while True:
        i = head.find(b'\x00\x00\x01', i)
        if i < 0 or i + 3 >= len(head):
            return False
        code = head[i + 3]
        if code in (0xB3, 0xB8):
            return True
        if code == 0x00 and i + 5 < len(head) and (head[i + 5] >> 3) & 7 == 1:
            return True
        i += 3


def find_seq_mpeg2(buf):
    """Breite, Hoehe, Bildrate, Profil und Zeilensprung aus Sequenzkopf (B3) und Sequenzerweiterung (B5)."""
    i = buf.find(b'\x00\x00\x01\xb3')
    if i < 0 or i + 11 > len(buf):
        return None
    b = buf[i + 4:]
    width = (b[0] << 4) | (b[1] >> 4)
    height = ((b[1] & 0x0F) << 8) | b[2]
    fps = MPEG2_FPS.get(b[3] & 0x0F)
    profile, level, interlaced = 'MPEG-2', '', None
    j = buf.find(b'\x00\x00\x01\xb5', i)
    if j >= 0 and j + 8 < len(buf) and (buf[j + 4] >> 4) == 1:
        pl = ((buf[j + 4] & 0x0F) << 4) | (buf[j + 5] >> 4)
        profile = MPEG2_PROFILES.get((pl >> 4) & 7, 'MPEG-2')
        level = MPEG2_LEVELS.get(pl & 0x0F, '')
        interlaced = not ((buf[j + 5] >> 3) & 1)
    return {'profile': profile, 'level': level, 'width': width, 'height': height,
            'interlaced': bool(interlaced), 'sps_fps': fps}


def find_sps(buf):
    i = 0
    while True:
        i = buf.find(b'\x00\x00\x01', i)
        if i < 0 or i + 4 >= len(buf):
            return None
        if buf[i + 3] & 0x1f == 7:
            end = buf.find(b'\x00\x00\x01', i + 3)
            try:
                return parse_sps(buf[i + 3:end if end > 0 else len(buf)][:80])
            except Exception:
                return None
        i += 3


AC3_CHANNELS = [2, 1, 2, 3, 3, 4, 4, 5]


def descriptors(desc):
    pos = 0
    while pos + 2 <= len(desc):
        tag, ln = desc[pos], desc[pos + 1]
        yield tag, desc[pos + 2:pos + 2 + ln]
        pos += 2 + ln


def parse_ac3(es):
    i = es.find(b'\x0b\x77', 0, 4000)
    if i < 0 or len(es) < i + 10:
        return None
    b = Bits(es[i + 2:i + 12])
    b.u(16)
    fscod = b.u(2)
    b.u(6)
    b.u(5)
    b.u(3)
    acmod = b.u(3)
    if (acmod & 1) and acmod != 1:
        b.u(2)
    if acmod & 4:
        b.u(2)
    if acmod == 2:
        b.u(2)
    lfe = b.u(1)
    rate = {0: 48000, 1: 44100, 2: 32000}.get(fscod)
    return {'codec': 'AC-3', 'rate': rate, 'channels': AC3_CHANNELS[acmod] + lfe}


def parse_private(desc, es):
    """PES-privat (Typ 0x06): Format ueber die Deskriptoren der PMT bestimmen."""
    for tag, body in descriptors(desc):
        if tag == 0x05 and body[:4] == b'Opus':
            ch = None
            for t2, b2 in descriptors(desc):
                if t2 == 0x7f and len(b2) >= 2 and b2[0] == 0x80 and 1 <= b2[1] <= 8:
                    ch = b2[1]
            return {'codec': 'Opus', 'rate': 48000, 'channels': ch}
        if tag == 0x05 and body[:4] == b'BSSD':
            return {'codec': 'PCM (SMPTE 302M)', 'rate': 48000, 'channels': None}
        if tag == 0x6a:
            return parse_ac3(es) or {'codec': 'AC-3', 'rate': None, 'channels': None}
        if tag == 0x7a:
            return {'codec': 'E-AC-3', 'rate': None, 'channels': None}
    return None


def aac_pce_channels(es, i):
    """Kanalzahl aus dem Program Config Element (AAC mit mehr als 2 Kanaelen: channel_configuration = 0)."""
    hdr = 7 if es[i + 1] & 1 else 9
    b = Bits(es[i + hdr:i + hdr + 40])
    if b.u(3) != 5:           # ID_PCE
        return None
    b.u(4); b.u(2); b.u(4)
    nf, ns, nb, nl = b.u(4), b.u(4), b.u(4), b.u(2)
    b.u(3); b.u(4)            # Assoc-Daten, Coupling-Kanaele
    if b.u(1):
        b.u(4)
    if b.u(1):
        b.u(4)
    if b.u(1):
        b.u(3)
    total = 0
    for n in (nf, ns, nb):
        for _ in range(n):
            total += 2 if b.u(1) else 1
            b.u(4)
    return total + nl


def parse_audio(stype, es, desc=b''):
    if stype == 0x06:
        r = parse_private(desc, es)
        if r:
            return r
    if stype in (0x81, 0x06):
        r = parse_ac3(es)
        if r:
            return r
    if stype in (0x0f, 0x11) and len(es) >= 7:
        for i in range(min(len(es) - 7, 4000)):
            if es[i] == 0xFF and es[i + 1] & 0xF6 == 0xF0:
                profile = (es[i + 2] >> 6) + 1
                sf = (es[i + 2] >> 2) & 0xF
                ch = ((es[i + 2] & 1) << 2) | (es[i + 3] >> 6)
                if ch == 0:
                    try:
                        ch = aac_pce_channels(es, i)
                    except Exception:
                        ch = None
                elif ch == 7:
                    ch = 8      # channel_configuration 7 = 7.1 = 8 Kanaele
                return {'codec': 'AAC-LC' if profile == 2 else 'AAC (Profil %d)' % profile,
                        'rate': AAC_RATES[sf] if sf < len(AAC_RATES) else None, 'channels': ch}
    if stype in (0x03, 0x04) and len(es) >= 4:
        for i in range(min(len(es) - 4, 4000)):
            if es[i] == 0xFF and es[i + 1] & 0xE0 == 0xE0:
                ver = (es[i + 1] >> 3) & 3
                layer = {3: 1, 2: 2, 1: 3}.get((es[i + 1] >> 1) & 3)
                if layer is None or ver == 1:
                    continue
                br_i, sr_i = es[i + 2] >> 4, (es[i + 2] >> 2) & 3
                if br_i in (0, 15) or sr_i == 3:
                    continue
                table = MPA_BITRATES.get(layer if layer in (1, 2) else 1)
                name = {1: 'MP3 (Layer III)', 2: 'MP2 (Layer II)', 3: 'MPEG Layer I'}[layer]
                kbit = None
                if ver == 3 and layer in (2, 3):
                    kbit = (MPA_BITRATES[2] if layer == 2 else MPA_BITRATES[1])[br_i] if br_i < 15 else None
                return {'codec': name, 'rate': MPA_RATES[ver][sr_i], 'channels': 1 if (es[i + 3] >> 6) == 3 else 2,
                        'stream_kbit': kbit}
    return {'codec': STREAM_TYPES.get(stype, hex(stype)), 'rate': None, 'channels': None}


def analyse(packets):
    res = {'active': True, 'packets': len(packets)}
    pmt_pid = None
    pat_seen = False
    pmt = None
    count = {}
    cc_last = {}
    cc_err = 0
    pcr_pid = None
    pcr_list = []          # (Paketindex, PCR in ms)
    pes = {}               # pid -> Liste (Paketindex, PTS ms)
    rai = {}               # pid -> Paketindizes mit Random-Access-Indicator
    es_first = {}          # pid -> erste Nutzdaten (fuer Audio-Header)
    video_buf = bytearray()
    video_pid = audio_pid = None
    video_type = None
    mpeg2_seq = None
    audio_pids = []        # alle Audio-Spuren (z. B. mehrere Stereo-Paare)
    cur_pes = {}           # pid -> bytearray des laufenden PES (Anfang)
    vhead = None           # Anfang des laufenden Videobildes
    vframes = []           # je Bild: Keyframe ja/nein
    nulls = 0
    for idx, p in enumerate(packets):
        if p[0] != 0x47:
            continue
        pid = ((p[1] & 0x1f) << 8) | p[2]
        if pid == 0x1fff:
            nulls += 1
            continue
        count[pid] = count.get(pid, 0) + 1
        pusi = bool(p[1] & 0x40)
        afc = (p[3] >> 4) & 3
        cc = p[3] & 0xf
        off = 4
        disc = False
        if afc & 2:
            al = p[4]
            if al > 0:
                flags = p[5]
                disc = bool(flags & 0x80)
                if flags & 0x40:
                    rai.setdefault(pid, []).append(idx)
                if flags & 0x10 and al >= 7:
                    b = p[6:12]
                    base = (b[0] << 25) | (b[1] << 17) | (b[2] << 9) | (b[3] << 1) | (b[4] >> 7)
                    ext = ((b[4] & 1) << 8) | b[5]
                    if pcr_pid is None or pid == pcr_pid:
                        pcr_pid = pid
                        pcr_list.append((idx, (base * 300 + ext) / 27000.0))
            off = 5 + al
        has_payload = bool(afc & 1) and off < 188
        if has_payload:
            if pid in cc_last and not disc and cc != (cc_last[pid] + 1) & 0xf and cc != cc_last[pid]:
                cc_err += 1
            cc_last[pid] = cc
        if not has_payload:
            continue
        payload = p[off:]
        if pid == 0 and pusi and not pat_seen and len(payload) > 12:
            s = payload[1 + payload[0]:]
            n = ((s[1] & 0xf) << 8) | s[2]
            for k in range(0, n - 9, 4):
                prog = (s[8 + k] << 8) | s[9 + k]
                if prog != 0:
                    pmt_pid = ((s[10 + k] & 0x1f) << 8) | s[11 + k]
                    pat_seen = True
                    break
        elif pmt_pid is not None and pid == pmt_pid and pusi and pmt is None and len(payload) > 16:
            s = payload[1 + payload[0]:]
            sl = ((s[1] & 0xf) << 8) | s[2]
            ver = (s[5] >> 1) & 0x1f
            pcr_p = ((s[8] & 0x1f) << 8) | s[9]
            pil = ((s[10] & 0xf) << 8) | s[11]
            k = 12 + pil
            end = min(3 + sl - 4, len(s))
            streams = []
            while k + 5 <= end:
                st = s[k]
                ep = ((s[k + 1] & 0x1f) << 8) | s[k + 2]
                il = ((s[k + 3] & 0xf) << 8) | s[k + 4]
                streams.append({'type': st, 'pid': ep, 'desc': bytes(s[k + 5:k + 5 + il])})
                k += 5 + il
            pmt = {'pid': pmt_pid, 'version': ver, 'pcr_pid': pcr_p, 'streams': streams}
            for st in streams:
                if st['type'] in VIDEO_TYPES and video_pid is None:
                    video_pid = st['pid']
                    video_type = st['type']
                elif st['type'] in AUDIO_TYPES:
                    audio_pids.append(st['pid'])
                    if audio_pid is None:
                        audio_pid = st['pid']
        elif pid is not None and (pid == video_pid or pid in audio_pids):
            if pusi:
                t = pts_from_pes(payload)
                if t is not None:
                    pes.setdefault(pid, []).append((idx, t / 90.0))
                cur_pes[pid] = True
                if pid in audio_pids and pid not in es_first and len(payload) > 9:
                    es_first[pid] = bytearray(payload[9 + payload[8]:])
            elif pid in audio_pids and pid in es_first and len(es_first[pid]) < 6000:
                es_first[pid] += payload
            if pid == video_pid and len(video_buf) < MAX_VIDEO_BYTES:
                video_buf += payload
            if pid == video_pid:
                if pusi:
                    if vhead is not None:
                        vframes.append((is_keyframe_mpeg2 if video_type == 2 else is_keyframe)(bytes(vhead)))
                        if video_type == 2 and mpeg2_seq is None:
                            mpeg2_seq = find_seq_mpeg2(bytes(vhead))
                    vhead = bytearray(payload[9 + payload[8]:]) if len(payload) > 9 else bytearray()
                elif vhead is not None and len(vhead) < 2500:
                    vhead += payload

    if pmt is None:
        res['active'] = False
        res['error'] = 'keine PMT im Abgriff'
        return res

    # Zeitbasis: PCR-Spanne (genauer als die Wanduhr, auch bei buerstiger Lieferung)
    span = None
    if len(pcr_list) >= 2:
        span = (pcr_list[-1][1] - pcr_list[0][1]) / 1000.0
    if not span or span < 0.5:
        span = None
    res['window_s'] = round(span, 2) if span else None
    total_pk = sum(count.values())
    res['pmt'] = {'pid': pmt['pid'], 'version': pmt['version'], 'pcr_pid': pmt['pcr_pid']}
    streams = []
    for st in pmt['streams']:
        pid = st['pid']
        n = count.get(pid, 0)
        kbit = round(n * 188 * 8 / span / 1000.0) if span else None
        streams.append({'pid': pid, 'type': st['type'], 'name': STREAM_TYPES.get(st['type'], hex(st['type'])),
                        'role': 'video' if st['type'] in VIDEO_TYPES else ('audio' if st['type'] in AUDIO_TYPES else 'daten'),
                        'kbit': kbit, 'packets': n})
    res['streams'] = streams
    res['total_kbit'] = round(total_pk * 188 * 8 / span / 1000.0) if span else None
    res['null_kbit'] = round(nulls * 188 * 8 / span / 1000.0) if span else None
    res['continuity_errors'] = cc_err

    def intervals(l):
        return [b[1] - a[1] for a, b in zip(l, l[1:]) if 0 < b[1] - a[1] < 1000]

    # Video
    if video_pid is not None:
        v = {'pid': video_pid}
        sps = (mpeg2_seq or find_seq_mpeg2(bytes(video_buf))) if video_type == 2 else (find_sps(bytes(video_buf)) if video_buf else None)
        if sps:
            v.update({k: sps[k] for k in ('profile', 'level', 'width', 'height', 'interlaced')})
        iv = intervals(pes.get(video_pid, []))
        if len(iv) >= 3:
            med = statistics.median(iv)
            v['fps'] = round(1000.0 / med, 2)
            v['interval_ms'] = round(med, 2)
            v['interval_dev_ms'] = round(max(abs(x - med) for x in iv), 2)
        # GOP-Laenge = Bilder zwischen zwei Keyframes (IDR)
        keys = [i for i, k in enumerate(vframes) if k]
        if len(keys) >= 2:
            gops = [b - a for a, b in zip(keys, keys[1:])]
            v['gop_frames'] = int(statistics.median(gops))
            if v.get('fps'):
                v['gop_s'] = round(v['gop_frames'] / v['fps'], 2)
        elif len(keys) == 1:
            v['gop_frames'] = None
        v['keyframes_in_window'] = len(keys)
        res['video'] = v
    # Audio: alle Spuren auswerten; 'audio' bleibt die erste (Kompatibilitaet), 'audios' enthaelt alle
    audios = []
    for apid in audio_pids:
        a = {'pid': apid}
        stype = next(x['type'] for x in pmt['streams'] if x['pid'] == apid)
        desc = next((x['desc'] for x in pmt['streams'] if x['pid'] == apid), b'')
        a.update(parse_audio(stype, bytes(es_first.get(apid, b'')), desc))
        iv = intervals(pes.get(apid, []))
        if len(iv) >= 3:
            med = statistics.median(iv)
            a['interval_ms'] = round(med, 2)
            a['interval_dev_ms'] = round(max(abs(x - med) for x in iv), 2)
        audios.append(a)
        # In der PID-Tabelle den erkannten Codec statt des allgemeinen Typnamens zeigen (z. B. Opus statt "PES privat")
        for st in res['streams']:
            if st['pid'] == apid and a.get('codec'):
                st['name'] = a['codec']
    if audios:
        res['audio'] = audios[0]
        res['audios'] = audios
    # PCR-Abstand
    if len(pcr_list) >= 3:
        d = [b[1] - a[1] for a, b in zip(pcr_list, pcr_list[1:])]
        res['pcr_interval_ms'] = {'median': round(statistics.median(d), 1), 'max': round(max(d), 1)}
    return res


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--port', type=int)
    ap.add_argument('--seconds', type=float, default=2.5)
    ap.add_argument('--file')
    args = ap.parse_args()
    if not args.file and not args.port:
        print(json.dumps({'active': False, 'error': 'Port oder Datei angeben'}))
        return 1
    packets, err = read_packets(args)
    if err:
        print(json.dumps({'active': False, 'error': err}))
        return 0
    if not packets:
        print(json.dumps({'active': False, 'error': 'keine Daten am Abgriff'}))
        return 0
    print(json.dumps(analyse(packets)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
