#!/bin/bash
# Leichter periodischer Gesundheitscheck fuer die DeckLink-Karte.
# Schreibt eine Zeile pro Lauf ins Log - damit man im Nachhinein sieht,
# wann genau der Daemon anfaengt zu spinnen oder eine Probe haengt/timeoutet.
# Greift nur Devices an, die gerade NICHT von einer laufenden Pipeline benutzt werden.

API="http://localhost:8000/api.php"
LOG="/opt/broadcast-gateway/data/decklink_health.log"
TS=$(date -Iseconds)

daemon_cpu=$(ps -C DesktopVideoHelper -o %cpu= 2>/dev/null | tr -d ' ')
[ -z "$daemon_cpu" ] && daemon_cpu="down"

busy_devices=$(curl -s --max-time 3 "$API?action=list" | jq -r '[.[] | select(.status=="running" and .source_type=="decklink") | .decklink_device_number] | join(",")')

probe_dev() {
  local dev=$1
  if [[ ",$busy_devices," == *",$dev,"* ]]; then
    echo "busy(skipped)"
    return
  fi
  local start=$(date +%s.%N)
  local out
  out=$(curl -s --max-time 8 "$API?action=decklink_probe&device=$dev&connection=auto")
  local dur=$(echo "$(date +%s.%N) - $start" | bc)
  local err
  err=$(echo "$out" | jq -r '.error // empty')
  if [ -n "$err" ]; then
    printf "FAIL(%.1fs:%s)" "$dur" "$err"
  else
    printf "ok(%.1fs)" "$dur"
  fi
}

dev0=$(probe_dev 0)
dev1=$(probe_dev 1)

flag=""
awk_check=$(echo "$daemon_cpu" | awk '{print ($1+0 > 15) ? "1" : "0"}' 2>/dev/null)
[ "$awk_check" = "1" ] && flag="$flag daemon_cpu_high"
[[ "$dev0" == FAIL* ]] && flag="$flag dev0_fail"
[[ "$dev1" == FAIL* ]] && flag="$flag dev1_fail"

echo "$TS daemon_cpu=${daemon_cpu}% dev0=$dev0 dev1=$dev1${flag:+  <<< $flag}" >> "$LOG"

# Log-Datei begrenzen, damit sie nicht unbegrenzt waechst - aber nur bei
# tatsaechlichem Bedarf trimmen, sonst ersetzt mv bei jedem Lauf die Datei
# und "tail -f" verliert staendig die Datei ("has been replaced").
if [ "$(wc -l < "$LOG")" -gt 20000 ]; then
  tail -n 10000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
fi
