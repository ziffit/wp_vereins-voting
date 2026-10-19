---
description: Distributierbares Plugin-ZIP bauen (ohne Test-/Sandbox-Bestandteile)
---

Baut ein WordPress-konformes Release-ZIP — nur die Plugin-Dateien, ohne Tests:

!`find vereins-voting -name '*.php' -o -name '*.css' | sort`

!`dir=/tmp/vv-ship-$(date +%s) && mkdir -p "$dir" && cp -r vereins-voting "$dir/" && (cd "$dir" && python3 -m zipfile -c vereins-voting.zip vereins-voting) && mv "$dir/vereins-voting.zip" . && unzip -l vereins-voting.zip`

Prüfe das Inhaltsverzeichnis: Es darf ausschließlich Dateien unter
`vereins-voting/` geben — keine Einträge aus `tests/`, `_verify/`, `spec/`
oder `wp-admin/`. Bei Auffälligkeiten das ZIP nicht weitergeben, sondern
zuerst korrigieren. Außergewöhnliche Warte (kein `uninstall.php` im Plugin,
keine Löschlogik für voting-Tabellen)#

 Schau: kurz benennen, wo das ZIP liegt und was drin ist.
