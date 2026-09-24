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


def main() -> None:
    device_number = int(sys.argv[1])
    connection = sys.argv[2] if len(sys.argv) > 2 else "auto"
    timeout_s = float(sys.argv[3]) if len(sys.argv) > 3 else 4.0

    Gst.init(None)

    result = {"signal": False, "width": None, "height": None, "framerate": None, "error": None}

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
        try:
            result["signal"] = bool(src.get_property("signal"))
        except Exception:
            pass

        pad = src.get_static_pad("src")
        caps = pad.get_current_caps() if pad else None
        if caps and caps.get_size() > 0:
            structure = caps.get_structure(0)
            ok_w, width = structure.get_int("width")
            ok_h, height = structure.get_int("height")
            if ok_w and ok_h:
                result["width"] = width
                result["height"] = height
            ok_fr, num, den = structure.get_fraction("framerate")
            if ok_fr and den:
                result["framerate"] = "{}/{}".format(num, den)
        return result["width"] is not None

    def on_poll():
        if capture_state():
            loop.quit()
            return False
        return True

    def on_timeout():
        capture_state()
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
