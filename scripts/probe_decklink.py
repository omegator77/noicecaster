#!/usr/bin/env python3
"""Prueft kurz, ob an einem DeckLink-Eingang ein Signal anliegt und welches Format erkannt wird.
Aufruf: probe_decklink.py <device-number> <connection> [timeout-sekunden]
Gibt ein JSON-Objekt auf stdout aus und beendet sich danach garantiert wieder.
"""
import sys
import json

import gi

gi.require_version("Gst", "1.0")
from gi.repository import GLib, Gst


def format_name(height: int, num: int, den: int, interlaced: bool) -> str:
    """z. B. 1080i50 (Halbbildrate), 1080p25, 720p59.94"""
    fps = num / den
    rate = 2 * fps if interlaced else fps
    text = "{:.2f}".format(rate).rstrip("0").rstrip(".")
    return "{}{}{}".format(height, "i" if interlaced else "p", text)


def main() -> None:
    device_number = int(sys.argv[1])
    connection = sys.argv[2] if len(sys.argv) > 2 else "auto"
    timeout_s = float(sys.argv[3]) if len(sys.argv) > 3 else 4.0

    Gst.init(None)

    result = {"signal": False, "width": None, "height": None, "framerate": None, "interlaced": None,
              "format": None, "error": None}
    last = {"fmt": None}

    try:
        pipeline = Gst.parse_launch(
            "decklinkvideosrc name=src device-number={} connection={} mode=auto ! fakesink sync=false".format(
                device_number, connection
            )
        )
    except GLib.Error as exc:
        result["error"] = str(exc)
        print(json.dumps(result))
        return

    src = pipeline.get_by_name("src")
    loop = GLib.MainLoop()

    def capture_state() -> bool:
        """True, sobald ein Signal anliegt und sein Format zweimal hintereinander gleich gemeldet wurde.

        Die Karte meldet direkt nach dem Start Ersatzwerte (720x486, 29,97) ohne Signal; das echte Format
        kommt erst nach ca. 1-2 s. Deshalb wird nicht beim ersten Format abgebrochen, sondern erst, wenn
        `signal` wahr ist und sich das Format nicht mehr aendert."""
        try:
            result["signal"] = bool(src.get_property("signal"))
        except Exception:
            pass

        fmt = None
        pad = src.get_static_pad("src")
        caps = pad.get_current_caps() if pad else None
        if caps and caps.get_size() > 0:
            structure = caps.get_structure(0)
            ok_w, width = structure.get_int("width")
            ok_h, height = structure.get_int("height")
            ok_fr, num, den = structure.get_fraction("framerate")
            if ok_w and ok_h and ok_fr and den:
                interlaced = (structure.get_string("interlace-mode") or "progressive") != "progressive"
                fmt = (width, height, num, den, interlaced)
        if not result["signal"] or fmt is None:
            last["fmt"] = None
            return False

        width, height, num, den, interlaced = fmt
        result["width"], result["height"] = width, height
        result["framerate"] = "{}/{}".format(num, den)
        result["interlaced"] = interlaced
        result["format"] = format_name(height, num, den, interlaced)
        stable = last["fmt"] == fmt
        last["fmt"] = fmt
        return stable

    def on_poll():
        if capture_state():
            loop.quit()
            return False
        return True

    def on_timeout():
        capture_state()
        if not result["signal"]:
            # Ohne Signal sind die gemeldeten Werte nur Ersatzwerte der Karte, nicht anzeigen
            result["width"] = result["height"] = result["framerate"] = result["interlaced"] = result["format"] = None
        loop.quit()
        return False

    def on_message(_bus, message):
        if message.type == Gst.MessageType.ERROR:
            err, _debug = message.parse_error()
            result["error"] = str(err)
            loop.quit()
        return True

    bus = pipeline.get_bus()
    bus.add_signal_watch()
    bus.connect("message", on_message)

    pipeline.set_state(Gst.State.PLAYING)
    GLib.timeout_add(200, on_poll)
    GLib.timeout_add(int(timeout_s * 1000), on_timeout)

    try:
        loop.run()
    finally:
        pipeline.set_state(Gst.State.NULL)

    print(json.dumps(result))


if __name__ == "__main__":
    main()
