#!/bin/bash
# Zeigt alle Pipelines mit Status; fuer laufende zusaetzlich Live-Stats.
API="http://localhost:8000/api.php"

curl -s "$API?action=list" | jq -c '.[]' | while read -r p; do
  id=$(echo "$p" | jq -r '.id')
  name=$(echo "$p" | jq -r '.name')
  status=$(echo "$p" | jq -r '.status')
  pid=$(echo "$p" | jq -r '.pid')

  if [ "$status" = "running" ]; then
    stats=$(curl -s "$API?action=stats&id=$id")
    uptime=$(echo "$stats" | jq -r '.uptime_seconds')
    cpu=$(echo "$stats" | jq -r '.cpu_percent')
    cores=$(echo "$stats" | jq -r '.cpu_cores')
    mem=$(echo "$stats" | jq -r '.memory_kb')
    client=$(echo "$stats" | jq -r '.client_connected')
    printf "[%2s] %-20s RUNNING  pid=%-7s uptime=%-5s cpu=%s%% (%s Kerne)  mem=%sKB  client=%s\n" \
      "$id" "$name" "$pid" "${uptime}s" "$cpu" "$cores" "$mem" "$client"
  else
    printf "[%2s] %-20s %s\n" "$id" "$name" "$(echo "$status" | tr 'a-z' 'A-Z')"
  fi
done
