#!/usr/bin/env python3
"""Prueft eine hochgeladene SVG-Vorlage fuer die Bauchbinde (wird von LowerThird.php aufgerufen, SVG kommt ueber stdin).

Die Vorlage wird spaeter von librsvg gerendert und ihr Bild landet im Sendebild, deshalb gilt eine strenge Positivliste:
  * nur wohlgeformtes XML ohne DOCTYPE/ENTITY, Wurzel <svg> im SVG-Namensraum, hoechstens 1 MB, 5000 Elemente, Tiefe 40
  * verboten: script, foreignObject, iframe, object, embed, audio, video, canvas, animate*, set, Ereignis-Attribute (on...)
  * Verweise (href, xlink:href) nur auf "#id" oder eingebettete Bilder (data:image/png|jpeg|gif|webp;base64,...), nie auf Dateien/URLs
  * in style-Attributen und <style> keine @import, kein javascript:, url(...) nur auf "#id" oder eingebettete Bilder
  * Platzhalter nur aus der bekannten Liste, mindestens einer der Textplatzhalter {{TITLE}}, {{MAIN}}, {{SUB}}
  * Groesse aus viewBox (sonst width/height), je 100 bis 4000 Einheiten
Optional: ein Element mit id="logo" (rect/image/g mit x, y, width, height) markiert die Logo-Position (bitte unsichtbar, fill="none");
ein Kommentar <!-- noicecaster: {...JSON...} --> kann name, boxes (title/main/sub mit w, size, upper) und logo setzen.
Ausgabe: eine JSON-Zeile {"ok": true, "width", "height", "logo", "boxes", "name", "placeholders"} oder {"ok": false, "error": "..."}.
"""
import json
import re
import sys
import xml.etree.ElementTree as ET

SVG_NS = "http://www.w3.org/2000/svg"
XLINK_NS = "http://www.w3.org/1999/xlink"
PLACEHOLDERS = {"TITLE", "MAIN", "SUB", "ACCENT", "ACCENT2", "LOGO", "TITLE_SIZE", "MAIN_SIZE", "SUB_SIZE"}
TEXT_PLACEHOLDERS = {"TITLE", "MAIN", "SUB"}
FORBIDDEN = {"script", "foreignobject", "iframe", "object", "embed", "audio", "video", "canvas", "animate", "animatemotion",
             "animatetransform", "animatecolor", "set", "handler", "listener", "discard"}
DATA_IMAGE = re.compile(r"^data:image/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=\s]+$", re.I)
URL_REF = re.compile(r"url\(\s*(['\"]?)(.*?)\1\s*\)", re.I | re.S)
MAX_BYTES = 1024 * 1024


class Reject(Exception):
    pass


def local(name: str) -> str:
    return name.rsplit("}", 1)[-1].lower() if name.startswith("{") else name.lower()


def check_css(text: str, where: str) -> None:
    low = text.lower()
    if "@import" in low or "javascript:" in low or "expression(" in low:
        raise Reject(f"{where}: @import, javascript: und expression() sind nicht erlaubt")
    for m in URL_REF.finditer(text):
        target = m.group(2).strip()
        if not (target.startswith("#") or DATA_IMAGE.match(target)):
            raise Reject(f"{where}: url(...) darf nur auf #id oder eingebettete Bilder zeigen")


def to_number(value, what: str) -> float:
    if value is None:
        raise Reject(f"{what} fehlt (width/height oder viewBox angeben)")
    m = re.fullmatch(r"\s*(-?\d+(?:\.\d+)?)\s*(px)?\s*", str(value))
    if not m:
        raise Reject(f"{what} ist keine Zahl: {value!r}")
    return float(m.group(1))


def main() -> int:
    raw = sys.stdin.buffer.read(MAX_BYTES + 1)
    if len(raw) > MAX_BYTES:
        raise Reject("Die Vorlage darf hoechstens 1 MB gross sein")
    low = raw.lower()
    if b"<!doctype" in low or b"<!entity" in low or b"<?xml-stylesheet" in low:
        raise Reject("DOCTYPE, ENTITY und xml-stylesheet sind nicht erlaubt")
    text = raw.decode("utf-8", errors="strict")
    parser = ET.XMLParser(target=ET.TreeBuilder(insert_comments=True))
    try:
        root = ET.fromstring(text, parser=parser)
    except ET.ParseError as e:
        raise Reject(f"Kein gueltiges XML: {e}")
    if root.tag != f"{{{SVG_NS}}}svg":
        raise Reject("Die Wurzel muss <svg xmlns=\"http://www.w3.org/2000/svg\"> sein")

    count, meta_comment, logo_el = 0, None, None

    def walk(el, depth):
        nonlocal count, meta_comment, logo_el
        if depth > 40:
            raise Reject("Das SVG ist zu tief verschachtelt")
        for child in list(el):
            if child.tag is ET.Comment:
                m = re.match(r"\s*noicecaster:\s*(\{.*\})\s*$", child.text or "", re.S)
                if m and meta_comment is None:
                    try:
                        meta_comment = json.loads(m.group(1))
                    except ValueError:
                        raise Reject("Der noicecaster-Kommentar enthaelt kein gueltiges JSON")
                continue
            if not isinstance(child.tag, str):
                raise Reject("Verarbeitungsanweisungen sind nicht erlaubt")
            count += 1
            if count > 5000:
                raise Reject("Das SVG hat zu viele Elemente (hoechstens 5000)")
            name = local(child.tag)
            if name in FORBIDDEN:
                raise Reject(f"Das Element <{name}> ist nicht erlaubt")
            if name == "style":
                check_css("".join(child.itertext()), "<style>")
            for attr, value in child.attrib.items():
                an = local(attr) if attr.startswith("{") else attr.lower()
                if an.startswith("on"):
                    raise Reject(f"Ereignis-Attribut {an} ist nicht erlaubt")
                if an == "href":
                    v = value.strip()
                    if not (v.startswith("#") or DATA_IMAGE.match(v)):
                        raise Reject("Verweise (href) duerfen nur auf #id oder eingebettete Bilder (data:image/png|jpeg|gif|webp;base64) zeigen")
                    if name == "use" and not v.startswith("#"):
                        raise Reject("<use> darf nur auf #id zeigen")
                elif an == "style" or "url(" in value.lower():
                    check_css(value, f"Attribut {an}")
                if an == "id" and value == "logo" and logo_el is None:
                    logo_el = child
            walk(child, depth + 1)

    for attr, value in root.attrib.items():
        an = local(attr) if attr.startswith("{") else attr.lower()
        if an.startswith("on"):
            raise Reject(f"Ereignis-Attribut {an} ist nicht erlaubt")
    walk(root, 0)

    found = set(re.findall(r"\{\{([A-Za-z0-9_]+)\}\}", text))
    unknown = sorted(found - PLACEHOLDERS)
    if unknown:
        raise Reject("Unbekannte Platzhalter: " + ", ".join("{{" + u + "}}" for u in unknown) + " (erlaubt: " + ", ".join("{{" + p + "}}" for p in sorted(PLACEHOLDERS)) + ")")
    if not (found & TEXT_PLACEHOLDERS):
        raise Reject("Die Vorlage braucht mindestens einen Textplatzhalter: {{TITLE}}, {{MAIN}} oder {{SUB}}")

    vb = root.attrib.get("viewBox")
    if vb:
        parts = re.split(r"[\s,]+", vb.strip())
        if len(parts) != 4:
            raise Reject("viewBox muss vier Zahlen haben")
        width, height = to_number(parts[2], "viewBox-Breite"), to_number(parts[3], "viewBox-Hoehe")
    else:
        width, height = to_number(root.attrib.get("width"), "width"), to_number(root.attrib.get("height"), "height")
    if not (100 <= width <= 4000 and 100 <= height <= 4000):
        raise Reject("Breite und Hoehe muessen zwischen 100 und 4000 liegen")
    if not (0.2 <= width / height <= 30):
        raise Reject("Das Seitenverhaeltnis ist ungeeignet (Breite/Hoehe zwischen 0,2 und 30)")

    meta = meta_comment if isinstance(meta_comment, dict) else {}
    boxes = {
        "title": {"w": round(width * 0.4), "size": max(14, round(height * 0.12)), "upper": False},
        "main": {"w": round(width * 0.6), "size": max(18, round(height * 0.2)), "upper": False},
        "sub": {"w": round(width * 0.5), "size": max(14, round(height * 0.14)), "upper": False},
    }
    for key, box in (meta.get("boxes") or {}).items():
        if key in boxes and isinstance(box, dict):
            for field in ("w", "size"):
                if field in box:
                    boxes[key][field] = max(10, min(4000, int(to_number(box[field], f"boxes.{key}.{field}"))))
            if "upper" in box:
                boxes[key]["upper"] = bool(box["upper"])
    logo = None
    if isinstance(meta.get("logo"), dict):
        lg = meta["logo"]
        logo = {k: int(to_number(lg.get(k), f"logo.{k}")) for k in ("x", "y", "w", "h")}
    elif logo_el is not None:
        a = logo_el.attrib
        try:
            logo = {"x": int(to_number(a.get("x", 0), "logo x")), "y": int(to_number(a.get("y", 0), "logo y")),
                    "w": int(to_number(a.get("width"), "logo width")), "h": int(to_number(a.get("height"), "logo height"))}
        except Reject:
            logo = None
    if logo is not None and not (logo["w"] > 0 and logo["h"] > 0):
        logo = None
    name = str(meta.get("name") or "")[:60]
    print(json.dumps({"ok": True, "width": int(round(width)), "height": int(round(height)), "boxes": boxes, "logo": logo, "name": name,
                      "placeholders": sorted(found)}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Reject as e:
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=False))
    except UnicodeDecodeError:
        print(json.dumps({"ok": False, "error": "Die Datei ist kein gueltiges UTF-8"}))
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"Pruefung fehlgeschlagen: {e}"}))
    sys.exit(0)
