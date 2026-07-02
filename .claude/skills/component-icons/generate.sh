#!/usr/bin/env bash
# Generate colored Phosphor-duotone GLYPHS for Synapse Icons.php.
# Each entry: synapse_key|phosphor_name|hex_color  (color by category, premium palette).
set -u
BASE="https://unpkg.com/@phosphor-icons/core@2.1.1/assets/duotone"
OUT=/tmp/claude-1000/-home-andre-global-view-next/0cf1fa93-7a96-4116-9f0e-cd36dcdb18e2/scratchpad/glyphs.php
MISS=/tmp/claude-1000/-home-andre-global-view-next/0cf1fa93-7a96-4116-9f0e-cd36dcdb18e2/scratchpad/glyphs.miss
: > "$OUT"; : > "$MISS"

# category palette (Tailwind-500-ish, vibrant + cohesive)
IND="#6366F1"; SLT="#64748B"; CYN="#06B6D4"; VIO="#8B5CF6"; AMB="#F59E0B"; EMR="#10B981"; ROS="#F43F5E"

MAP="
navbar|browser|$IND
hero|text-h-one|$IND
features|squares-four|$IND
logos|buildings|$IND
stats|chart-line-up|$IND
gallery|images-square|$IND
pricing|tag|$IND
testimonial|quotes|$IND
faq|question|$IND
team|users-three|$IND
cta|cursor-click|$IND
contact|envelope-simple|$IND
footer|square-half-bottom|$IND
text|text-align-left|$SLT
heading|text-h|$SLT
image|image|$SLT
button|hand-pointing|$SLT
columns-2|columns|$SLT
columns-3|grid-four|$SLT
spacer|arrows-out-line-vertical|$SLT
divider|minus|$SLT
shape-wave|wave-sine|$CYN
shape-slant|line-segment|$CYN
shape-tilt|wave-sawtooth|$CYN
shape-curve|wave-triangle|$CYN
card|cards|$VIO
banner|flag-banner|$VIO
modal|app-window|$VIO
drawer|sidebar-simple|$VIO
tabs|browsers|$VIO
accordion|rows|$VIO
tooltip|chat-teardrop-dots|$VIO
dropdown_menu|caret-circle-down|$VIO
alert|warning-circle|$VIO
avatar|user-circle|$VIO
breadcrumbs|dots-three-outline|$VIO
progress|circle-half|$VIO
rating|star|$VIO
video|play-circle|$VIO
text_input|textbox|$AMB
email_input|envelope|$AMB
textarea|note|$AMB
select|list-dashes|$AMB
checkbox|check-square|$AMB
radio_group|radio-button|$AMB
submit_button|paper-plane-tilt|$AMB
form|note-pencil|$AMB
date_picker|calendar-blank|$AMB
file_upload|file-arrow-up|$AMB
data_table|table|$EMR
list|list-bullets|$EMR
kpi|gauge|$EMR
chart|chart-bar|$EMR
embed|frame-corners|$EMR
repeater|rows-plus-bottom|$ROS
editable_grid|grid-four|$ROS
stepper|plus-minus|$ROS
context_menu|dots-three-vertical|$ROS
record_picker|list-magnifying-glass|$ROS
autocomplete|magnifying-glass|$ROS
"

echo "$MAP" | while IFS='|' read -r key name color; do
  [ -z "$key" ] && continue
  svg=$(curl -sL -m 15 "$BASE/${name}-duotone.svg" 2>/dev/null)
  if ! echo "$svg" | grep -q '<path'; then
    echo "$key -> $name (MISS)" >> "$MISS"
    continue
  fi
  # colorize: set the svg fill to the category color; add sizing style; strip xmlns dupes
  svg=$(echo "$svg" | sed -E "s/fill=\"currentColor\"/fill=\"${color}\"/")
  svg=$(echo "$svg" | sed -E "s/<svg /<svg style=\"width:26px;height:26px\" /")
  # collapse whitespace/newlines to one line, escape single quotes for PHP single-quoted string
  svg=$(echo "$svg" | tr -d '\n' | sed "s/'/\\\\'/g")
  printf "        '%s' => '%s',\n" "$key" "$svg" >> "$OUT"
done

echo "=== generated $(wc -l < "$OUT") entries ==="
echo "=== misses ===" ; cat "$MISS" 2>/dev/null || echo "none"
